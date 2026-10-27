# YSRTech_ImageCleanerCron

A Magento 1 / OpenMage module that cleans up orphaned images on a schedule, from
Magento cron. It finds product images, category images, product image cache and
(optionally) WYSIWYG images that nothing uses, and moves them to a dated trash
folder instead of deleting them. Trash older than a set number of days is deleted
on the next run.

It uses the same rules as the admin Sync buttons of
[fballiano/openmage-image-cleaner](https://github.com/fballiano/openmage-image-cleaner)
but does not need that module. When it is installed, files this module moves are
also removed from its grid.

Compatible with Magento 1.9 and OpenMage 19/20 on PHP 7.4 and later.

## Install

Copy `app/` into the Magento root, then flush the cache. There is no database
setup. Magento's cron (`cron.sh` / `cron.php`) must be running.

## Settings

System > Configuration > Advanced > Admin > **Orphaned Image Clean-up**
(`admin/ysrtech_imagecleaner/*`):

| Setting | Default | |
|---|---|---|
| Enabled | Yes | |
| Mode | Move | *Report only* logs each file it would move and moves nothing |
| Schedule | `30 3 * * 0` | Cron expression in server time: Sundays 3:30 am |
| Product images | Yes | Files in `media/catalog/product` that no product uses (media gallery, image attributes, placeholders) |
| Category images | Yes | Files in `media/catalog/category` that no category image attribute uses |
| Product image cache | Yes | Resized copies whose original no longer exists |
| WYSIWYG images | No | Files in `media/wysiwyg` that no CMS page, block, email template, product or category description, or theme stylesheet mentions. Off by default: emails sent from elsewhere may still link to them |
| Never touch | (empty) | One path per line, relative to `media`, wildcards allowed; a folder covers everything in it, e.g. `catalog/product/lookbook` |
| Leave files changed in the last (hours) | 48 | |
| Stop if more than this % look unused | 80 | |
| Keep moved files for (days) | 30 | |

## Safety

Nobody reviews the list before a scheduled run, so:

- **Nothing is deleted straight away.** Files go to
  `var/imagecleaner_trash/<YYYY-MM-DD>/media/...`, keeping their path. To restore
  one, move it back to the same path under the Magento root.
- **Recent files are left**, so an image uploaded while the job runs is never
  caught before its product is saved.
- **WebP copies** (`name.webp` next to `name.jpg`/`.png`, as written by
  Yireo_Webp and similar) are left while their original exists.
- **A section stops without moving anything** when no images in use are found
  in the database, or when more than the configured share of its files look
  unused. Either points at a database problem rather than real orphans.
- **One run at a time**, guarded by a lock file in `var/locks`.
- Folders are walked one file at a time, so memory stays flat on a large media
  folder.

## Log

Every run, and every file moved (or, in report-only mode, every file it would
move), is written to `var/log/imagecleaner_cron.log`.

## Running it by hand

From the Magento root:

```
# report only
php -r 'require "app/Mage.php"; Mage::app("admin"); $r = Mage::getModel("ysrtech_imagecleanercron/cleaner")->run(true); echo $r["summary"], "\n";'

# move unused images to the trash
php -r 'require "app/Mage.php"; Mage::app("admin"); $r = Mage::getModel("ysrtech_imagecleanercron/cleaner")->run(false); echo $r["summary"], "\n";'

# exactly what the cron job does (follows Enabled and Mode)
php -r 'require "app/Mage.php"; Mage::app("admin"); echo Mage::getModel("ysrtech_imagecleanercron/cleaner")->cronRun(), "\n";'
```

## Tests

```
php dev/tests/imagecleanercron/cleaner_test.php
```

Checks the safety rules (age window, WebP, ratio limit, never-touch matching,
cache-to-original mapping, folder walk). No database or Magento install needed.

## Licence

[OSL 3.0](https://opensource.org/licenses/osl-3.0.php)
