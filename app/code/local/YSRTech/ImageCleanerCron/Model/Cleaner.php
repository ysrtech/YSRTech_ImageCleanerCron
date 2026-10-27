<?php
/**
 * Weekly orphaned image clean-up, run from Magento cron: finds images nothing uses
 * (the same rules as Fballiano_ImageCleaner's Sync buttons, without needing that
 * module) and moves them to var/imagecleaner_trash/<date>/ rather than deleting
 * them. Trash folders older than the configured number of days are deleted on the
 * next run. In "report only" mode it logs what it would move and moves nothing.
 *
 * Safety, because nobody reviews the list first:
 * - files changed in the last few hours are left (an image can land in
 *   media/catalog/product a moment before its product row is saved);
 * - a .webp next to its original (Yireo_Webp) is left, or it would be moved and
 *   recreated every week;
 * - a section stops without moving anything when nothing in use is found or when
 *   more than the configured share of its files look unused;
 * - the "Never touch" list is honoured.
 *
 * Files are walked one at a time, so memory stays flat on a large media folder.
 *
 * @category  YSRTech
 * @package   YSRTech_ImageCleanerCron
 */
class YSRTech_ImageCleanerCron_Model_Cleaner
{
    const XML_PATH = 'admin/ysrtech_imagecleaner/';
    const LOG_FILE = 'imagecleaner_cron.log';

    /** Folders the admin Sync Products / Categories buttons never look in. */
    protected static $_skipDirs = array('cache', 'watermark', 'optimized', '.thumbs');

    /** @var bool */
    protected $_dryRun = false;

    /** @var array "Never touch" patterns, relative to the media folder */
    protected $_blacklist = array();

    /** @var int */
    protected $_now;

    /**
     * Cron entry point. The summary goes to var/log/imagecleaner_cron.log.
     *
     * @return string summary
     */
    public function cronRun()
    {
        if (!Mage::getStoreConfigFlag(self::XML_PATH . 'enabled')) {
            return 'Disabled in System > Configuration > Advanced > Admin > Orphaned Image Clean-up.';
        }
        $mode   = (string) Mage::getStoreConfig(self::XML_PATH . 'mode');
        $report = $this->run($mode === YSRTech_ImageCleanerCron_Model_System_Config_Source_Mode::REPORT);
        return $report['summary'];
    }

    /**
     * @param  bool  $dryRun only report, move nothing
     * @return array summary, sections => array(name => array(scanned, orphans, moved, skipped, error))
     */
    public function run($dryRun = true)
    {
        $this->_dryRun    = (bool) $dryRun;
        $this->_now       = time();
        $this->_blacklist = self::parseList((string) Mage::getStoreConfig(self::XML_PATH . 'blacklist'));

        $lock = $this->_lock();
        if (!$lock) {
            $this->_say('Another clean-up is still running; nothing done.');
            return array('summary' => 'Another clean-up is still running.', 'sections' => array());
        }

        $this->_say(sprintf('Image clean-up %s (%s)', date('Y-m-d H:i:s', $this->_now), $this->_dryRun ? 'report only, nothing moved' : 'moving to trash'));
        $sections = array();
        try {
            $sections['products']      = $this->_section('products', 'Product images', array($this, '_cleanProducts'));
            $sections['categories']    = $this->_section('categories', 'Category images', array($this, '_cleanCategories'));
            $sections['product_cache'] = $this->_section('product_cache', 'Product image cache', array($this, '_cleanProductCache'));
            $sections['wysiwyg']       = $this->_section('wysiwyg', 'WYSIWYG images', array($this, '_cleanWysiwyg'));
            if (!$this->_dryRun) {
                $this->_purgeTrash();
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $parts = array();
        foreach ($sections as $name => $s) {
            if ($s === null) {
                continue;
            }
            $parts[] = $s['error']
                ? sprintf('%s: stopped (%s)', $name, $s['error'])
                : sprintf('%s: %d %s of %d', $name, $this->_dryRun ? $s['orphans'] : $s['moved'], $this->_dryRun ? 'unused' : 'moved', $s['scanned']);
        }
        $summary = $parts ? implode('; ', $parts) : 'No section enabled.';
        $this->_say($summary);
        return array('summary' => $summary, 'sections' => $sections);
    }

    /**
     * Run one section if it is switched on.
     *
     * @return array|null null when switched off
     */
    protected function _section($key, $title, $callback)
    {
        if (!Mage::getStoreConfigFlag(self::XML_PATH . $key)) {
            return null;
        }
        $this->_say('');
        $this->_say($title);
        $stats = array('scanned' => 0, 'orphans' => 0, 'moved' => 0, 'skipped' => 0, 'error' => null);
        try {
            call_user_func_array($callback, array(&$stats));
        } catch (Exception $e) {
            Mage::logException($e);
            $stats['error'] = $e->getMessage();
        }
        if ($stats['error']) {
            $this->_say('  stopped: ' . $stats['error']);
        } else {
            $this->_say(sprintf('  %d files, %d unused, %d moved, %d left (recent, WebP or blacklisted)',
                $stats['scanned'], $stats['orphans'], $stats['moved'], $stats['skipped']));
        }
        return $stats;
    }

    // ----------------------------------------------------------------- sections

    protected function _cleanProducts(array &$stats)
    {
        $db       = $this->_db();
        $resource = Mage::getSingleton('core/resource');
        $typeId   = (int) Mage::getModel('catalog/product')->getResource()->getTypeId();
        $dir      = Mage::getBaseDir('media') . '/catalog/product';

        $attributes = $db->fetchCol($db->select()->from($resource->getTableName('eav/attribute'), 'attribute_id')
            ->where('entity_type_id = ?', $typeId)->where('frontend_input = ?', 'media_image'));
        $used = array();
        if ($attributes) {
            foreach ($db->fetchCol($db->select()->distinct()->from($resource->getTableName('catalog_product_entity_varchar'), 'value')
                ->where('attribute_id IN (?)', $attributes)->where("value IS NOT NULL AND value <> '' AND value <> 'no_selection'")) as $v) {
                $used[ltrim($v, '/')] = true;
            }
        }
        foreach ($db->fetchCol($db->select()->distinct()->from($resource->getTableName('catalog/product_attribute_media_gallery'), 'value')
            ->where("value IS NOT NULL AND value <> ''")) as $v) {
            $used[ltrim($v, '/')] = true;
        }
        if (!$used) {
            throw new Exception('no product images found in the database');
        }
        foreach ($db->fetchCol($db->select()->distinct()->from($resource->getTableName('core/config_data'), 'value')
            ->where('path LIKE ?', 'catalog/placeholder/%_placeholder')) as $v) {
            $used['placeholder/' . ltrim($v, '/')] = true;
        }

        $this->_cleanByReference($dir, $used, $typeId, $stats);
    }

    protected function _cleanCategories(array &$stats)
    {
        $db       = $this->_db();
        $resource = Mage::getSingleton('core/resource');
        $typeId   = (int) Mage::getModel('catalog/category')->getResource()->getTypeId();
        $dir      = Mage::getBaseDir('media') . '/catalog/category';
        if (!is_dir($dir)) {
            return;
        }

        $attributes = $db->fetchCol($db->select()->from($resource->getTableName('eav/attribute'), 'attribute_id')
            ->where('entity_type_id = ?', $typeId)->where('frontend_input = ?', 'image'));
        $used = array();
        if ($attributes) {
            foreach ($db->fetchCol($db->select()->distinct()->from($resource->getTableName('catalog_category_entity_varchar'), 'value')
                ->where('attribute_id IN (?)', $attributes)->where("value IS NOT NULL AND value <> ''")) as $v) {
                $used[ltrim($v, '/')] = true;
            }
        }
        if (!$used) {
            throw new Exception('no category images found in the database');
        }

        $this->_cleanByReference($dir, $used, $typeId, $stats);
    }

    /**
     * A resized copy lives at cache/<store>/<size>/<hash>/a/b/file.jpg; it is unused once
     * catalog/product/a/b/file.jpg is gone. Same rule as the admin "Sync Products Cache".
     */
    protected function _cleanProductCache(array &$stats)
    {
        $typeId = -(int) Mage::getModel('catalog/product')->getResource()->getTypeId();
        $root   = Mage::getBaseDir('media') . '/catalog/product';
        $dir    = $root . '/cache';
        if (!is_dir($dir)) {
            return;
        }

        $this->_walkAndMove($dir, array(), $typeId, $stats, function ($relative) use ($root) {
            if (strpos('/' . $relative, '/placeholder/') !== false) {
                return false;
            }
            return !file_exists($root . '/' . self::originalOfCachePath($relative));
        });
    }

    protected function _cleanWysiwyg(array &$stats)
    {
        $db       = $this->_db();
        $resource = Mage::getSingleton('core/resource');
        $media    = Mage::getBaseDir('media');
        $dir      = $media . '/wysiwyg';
        if (!is_dir($dir)) {
            return;
        }

        // Everything that can mention a media/wysiwyg file, as one lower-case haystack.
        $texts = array();
        $sources = array(
            array($resource->getTableName('cms/page'), 'content'),
            array($resource->getTableName('cms/block'), 'content'),
            array($resource->getTableName('core/email_template'), 'template_text'),
            array($resource->getTableName('core/email_template'), 'template_styles'),
            array($resource->getTableName('catalog_product_entity_text'), 'value'),
            array($resource->getTableName('catalog_category_entity_text'), 'value'),
        );
        foreach ($sources as $source) {
            foreach ($db->fetchCol($db->select()->from($source[0], $source[1])->where($source[1] . " LIKE '%wysiwyg%'")) as $t) {
                $texts[] = $t;
            }
        }
        foreach (self::files(Mage::getBaseDir('skin') . '/frontend') as $path => $relative) {
            if (strtolower(substr($path, -4)) === '.css') {
                $texts[] = (string) @file_get_contents($path);
            }
        }
        $haystack  = strtolower(implode("\n", $texts));
        $swatches  = Mage::getStoreConfigFlag('configswatches/general/enabled');

        $this->_walkAndMove($dir, self::$_skipDirs, -98, $stats, function ($relative) use ($haystack, $swatches) {
            if ($swatches && strpos($relative, 'swatches/') === 0) {
                return false;
            }
            return strpos($haystack, strtolower('wysiwyg/' . $relative)) === false;
        });
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Move files under $dir whose path relative to it is not in $used.
     */
    protected function _cleanByReference($dir, array $used, $typeId, array &$stats)
    {
        if (!is_dir($dir)) {
            return;
        }
        $this->_walkAndMove($dir, self::$_skipDirs, $typeId, $stats, function ($relative) use ($used) {
            return !isset($used[$relative]);
        });
    }

    /**
     * Walk $dir, collect files $isUnused says nobody uses, apply the safety rules,
     * and move them unless it is a dry run.
     *
     * @param string   $dir
     * @param array    $skipDirs directory names never entered
     * @param int      $typeId   Image Cleaner entity_type_id, to drop its grid rows
     * @param array    $stats
     * @param callable $isUnused fn(string $relative): bool
     */
    protected function _walkAndMove($dir, array $skipDirs, $typeId, array &$stats, $isUnused)
    {
        $minAge   = max(0, (int) Mage::getStoreConfig(self::XML_PATH . 'min_age_hours'));
        $maxRatio = max(0, (int) Mage::getStoreConfig(self::XML_PATH . 'max_ratio'));

        $candidates = array();
        foreach (self::files($dir, $skipDirs) as $path => $relative) {
            $stats['scanned']++;
            if (!$isUnused($relative)) {
                continue;
            }
            $stats['orphans']++;
            if (self::isBlacklisted($this->_mediaPath($path), $this->_blacklist)
                || self::isRecent(@filemtime($path), $this->_now, $minAge)
                || self::isWebpOfExistingImage($path)
            ) {
                $stats['skipped']++;
                continue;
            }
            $candidates[$path] = $relative;
        }

        if (self::tooManyUnused($stats['orphans'], $stats['scanned'], $maxRatio)) {
            throw new Exception(sprintf('%d of %d files look unused, over the %d%% limit; nothing moved',
                $stats['orphans'], $stats['scanned'], $maxRatio));
        }
        if ($this->_dryRun) {
            foreach ($candidates as $path => $relative) {
                $this->_say('  would move ' . $this->_mediaRelative($path));
            }
            return;
        }

        $moved = array();
        foreach ($candidates as $path => $relative) {
            if ($this->_moveToTrash($path)) {
                $moved[] = $relative;
                $stats['moved']++;
                Mage::log('moved ' . $this->_mediaRelative($path), Zend_Log::INFO, self::LOG_FILE, true);
            }
        }
        $this->_forgetInImageCleaner($typeId, $moved);
    }

    /**
     * Yields absolute path => path relative to $dir for every file, depth first,
     * without ever holding the whole list.
     *
     * @return Generator
     */
    public static function files($dir, array $skipDirs = array())
    {
        $filter = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
            function ($current) use ($skipDirs) {
                return !($current->isDir() && in_array($current->getFilename(), $skipDirs, true));
            }
        );
        $prefix = rtrim($dir, '/') . '/';
        foreach (new RecursiveIteratorIterator($filter) as $file) {
            if ($file->isFile()) {
                $path = $file->getPathname();
                yield $path => substr($path, strlen($prefix));
            }
        }
    }

    /**
     * Lines of a textarea setting, trimmed, blanks dropped.
     *
     * @return array
     */
    public static function parseList($text)
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $text)), 'strlen'));
    }

    /**
     * @param  string $mediaPath path relative to the media folder, e.g. catalog/product/a/b/x.jpg
     * @param  array  $patterns  "Never touch" lines; a folder covers everything inside it
     * @return bool
     */
    public static function isBlacklisted($mediaPath, array $patterns)
    {
        foreach ($patterns as $pattern) {
            $pattern = trim($pattern, '/');
            if ($pattern !== '' && (fnmatch($pattern, $mediaPath) || fnmatch($pattern . '/*', $mediaPath))) {
                return true;
            }
        }
        return false;
    }

    /** cache/<store>/<size>/<hash>/a/b/file.jpg -> a/b/file.jpg */
    public static function originalOfCachePath($relative)
    {
        return implode('/', array_slice(explode('/', $relative), -3));
    }

    /**
     * @param  int|false $mtime
     * @param  int       $now
     * @param  int       $hours
     * @return bool      true when the file is too new to touch (or its age is unknown)
     */
    public static function isRecent($mtime, $now, $hours)
    {
        if ($mtime === false) {
            return true;
        }
        return $hours > 0 && $mtime > $now - $hours * 3600;
    }

    /** A Yireo_Webp copy sits next to its original as name.webp. */
    public static function isWebpOfExistingImage($path)
    {
        if (strtolower(substr($path, -5)) !== '.webp') {
            return false;
        }
        $base = substr($path, 0, -5);
        foreach (array('.jpg', '.jpeg', '.png', '.JPG', '.JPEG', '.PNG') as $ext) {
            if (file_exists($base . $ext)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param  int $unused
     * @param  int $scanned
     * @param  int $maxPercent 0 = no limit
     * @return bool
     */
    public static function tooManyUnused($unused, $scanned, $maxPercent)
    {
        if ($maxPercent <= 0 || $scanned === 0) {
            return false;
        }
        return $unused * 100 > $scanned * $maxPercent;
    }

    /** var/imagecleaner_trash/2026-11-01/media/catalog/product/a/b/x.jpg */
    protected function _trashPathFor($path)
    {
        return $this->_trashRoot() . '/' . date('Y-m-d', $this->_now) . '/' . $this->_mediaRelative($path);
    }

    /** catalog/product/a/b/x.jpg, relative to the media folder (what "Never touch" lists). */
    protected function _mediaPath($path)
    {
        $base = rtrim(Mage::getBaseDir('media'), '/') . '/';
        return strpos($path, $base) === 0 ? substr($path, strlen($base)) : ltrim($path, '/');
    }

    /** media/catalog/product/a/b/x.jpg: restore by moving it back under the site root. */
    protected function _mediaRelative($path)
    {
        $base = rtrim(Mage::getBaseDir(), '/') . '/';
        return strpos($path, $base) === 0 ? substr($path, strlen($base)) : ltrim($path, '/');
    }

    protected function _trashRoot()
    {
        return Mage::getBaseDir('var') . '/imagecleaner_trash';
    }

    /** @return bool */
    protected function _moveToTrash($path)
    {
        $target = $this->_trashPathFor($path);
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            Mage::log('could not create ' . $dir, Zend_Log::ERR, self::LOG_FILE, true);
            return false;
        }
        if (@rename($path, $target)) {
            return true;
        }
        if (@copy($path, $target) && @unlink($path)) {
            return true;
        }
        Mage::log('could not move ' . $path, Zend_Log::ERR, self::LOG_FILE, true);
        return false;
    }

    /**
     * When Fballiano_ImageCleaner is installed, drop the moved files from its grid so
     * it does not list them again. Nothing else here needs that module.
     */
    protected function _forgetInImageCleaner($typeId, array $paths)
    {
        $db    = $this->_db();
        $table = Mage::getSingleton('core/resource')->getTableName('fb_imagecleaner_image');
        if (!$paths || !$db->isTableExists($table)) {
            return;
        }
        foreach (array_chunk($paths, 500) as $chunk) {
            $db->delete($table, array('entity_type_id = ?' => (int) $typeId, 'path IN (?)' => $chunk));
        }
    }

    /** Delete trash folders older than the configured number of days. */
    protected function _purgeTrash()
    {
        $days = max(1, (int) Mage::getStoreConfig(self::XML_PATH . 'trash_days'));
        $root = $this->_trashRoot();
        if (!is_dir($root)) {
            return;
        }
        $cutoff = date('Y-m-d', $this->_now - $days * 86400);
        foreach (scandir($root) as $name) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $name) && $name < $cutoff) {
                Varien_Io_File::rmdirRecursive($root . '/' . $name);
                $this->_say('Deleted trash from ' . $name);
            }
        }
    }

    /** @return resource|false */
    protected function _lock()
    {
        $dir = Mage::getBaseDir('var') . '/locks';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $fh = @fopen($dir . '/ysrtech_imagecleaner_weekly.lock', 'c');
        if (!$fh) {
            return false;
        }
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            return false;
        }
        return $fh;
    }

    /** @return Varien_Db_Adapter_Interface */
    protected function _db()
    {
        return Mage::getSingleton('core/resource')->getConnection('core_read');
    }

    protected function _say($line)
    {
        Mage::log($line, Zend_Log::INFO, self::LOG_FILE, true);
    }
}
