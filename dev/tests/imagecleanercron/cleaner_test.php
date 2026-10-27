<?php
/**
 * Safety rules of the weekly image clean-up (YSRTech_ImageCleanerCron).
 * No database or booted Magento needed.
 *
 *   php dev/tests/imagecleanercron/cleaner_test.php
 */
$root = dirname(__DIR__, 3);
require $root . '/app/code/local/YSRTech/ImageCleanerCron/Model/Cleaner.php';
$C = 'YSRTech_ImageCleanerCron_Model_Cleaner';

$fail = 0;
function check($label, $ok) { global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; }

$now = 1800000000;
check('file older than the window may move', !$C::isRecent($now - 49 * 3600, $now, 48));
check('file inside the window is left', $C::isRecent($now - 3600, $now, 48));
check('unknown age is left', $C::isRecent(false, $now, 48));
check('window 0 means no age rule', !$C::isRecent($now, $now, 0));

check('ratio under the limit is fine', !$C::tooManyUnused(79, 100, 80));
check('ratio over the limit stops', $C::tooManyUnused(81, 100, 80));
check('nothing scanned never stops', !$C::tooManyUnused(0, 0, 80));
check('limit 0 means no limit', !$C::tooManyUnused(100, 100, 0));

check('settings list: trimmed, blanks dropped', $C::parseList(" a \r\n\n b/c ") === array('a', 'b/c'));
check('never touch: exact file', $C::isBlacklisted('catalog/product/a/b/x.jpg', array('catalog/product/a/b/x.jpg')));
check('never touch: folder covers its files', $C::isBlacklisted('catalog/product/lookbook/2025/x.jpg', array('catalog/product/lookbook')));
check('never touch: wildcard', $C::isBlacklisted('wysiwyg/landing-summer/x.jpg', array('wysiwyg/landing*')));
check('never touch: leading slash ignored', $C::isBlacklisted('catalog/product/lookbook/x.jpg', array('/catalog/product/lookbook/')));
check('never touch: other paths still move', !$C::isBlacklisted('catalog/product/a/b/x.jpg', array('catalog/product/lookbook', 'wysiwyg/landing*')));
check('never touch: empty list touches nothing', !$C::isBlacklisted('catalog/product/a/b/x.jpg', array()));
check('cache path maps to its original', $C::originalOfCachePath('1/small_image/80x/abc123/t/e/shirt.jpg') === 't/e/shirt.jpg');

$dir = sys_get_temp_dir() . '/icc_test_' . getmypid();
mkdir("$dir/a/b", 0777, true);
mkdir("$dir/cache/1", 0777, true);
foreach (array('a/b/shirt.jpg', 'a/b/shirt.webp', 'a/b/lonely.webp', 'cache/1/x.jpg') as $f) {
    file_put_contents("$dir/$f", 'x');
}
check('webp next to its jpg is left', $C::isWebpOfExistingImage("$dir/a/b/shirt.webp"));
check('webp without an original may move', !$C::isWebpOfExistingImage("$dir/a/b/lonely.webp"));
check('jpg is not treated as webp', !$C::isWebpOfExistingImage("$dir/a/b/shirt.jpg"));

$found = iterator_to_array($C::files($dir, array('cache')));
sort($found);
check('walk skips the cache folder and returns paths relative to the root',
    $found === array('a/b/lonely.webp', 'a/b/shirt.jpg', 'a/b/shirt.webp'));
check('walk without skips includes cache', in_array('cache/1/x.jpg', iterator_to_array($C::files($dir)), true));

foreach (array('a/b/shirt.jpg', 'a/b/shirt.webp', 'a/b/lonely.webp', 'cache/1/x.jpg') as $f) {
    unlink("$dir/$f");
}
rmdir("$dir/a/b"); rmdir("$dir/a"); rmdir("$dir/cache/1"); rmdir("$dir/cache"); rmdir($dir);

echo $fail ? "$fail check(s) failed\n" : "All clean-up checks passed\n";
exit($fail ? 1 : 0);
