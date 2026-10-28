# Dixeo Image Editor

Filter that lets teachers replace images already embedded in course content. Manual crop, adjustments, and upload work inside Moodle. Generate and AI edit send the prompt and image to the Dixeo API through `local_dixeo`.

## What it does

Once the filter is enabled, an edit control appears on eligible embedded images. Teachers can:

- **Crop and adjust** the current image (rotate, flip, brightness, contrast) and save it back into the content file.
- **Upload** a replacement image. The previous bytes are kept in version history.
- **Generate** a new image from a text prompt, or **edit** the current image with instructions, when the Dixeo API is configured and the content image policy allows it.
- **Restore or delete** earlier versions from the history stored by this filter.

Without a Dixeo API key, generate and AI edit are unavailable. Crop, adjust, and upload do not call the API.

## Requirements

- Moodle 4.5+ (`$plugin->requires` is `2024100700`)
- PHP 8.1+
- [local_dixeo](https://github.com/dixeo/moodle-local_dixeo) **1.10.2** or later (`$plugin->dependencies` requires version `2026092800`)
- A Dixeo API key for generate and AI edit

## Acquiring a Dixeo API key

You will receive a Dixeo API key within 48 hours of payment.

In case of delay or difficulty, please contact support@dixeo.com.

Configure the key in **Site administration → Plugins → Local plugins → Dixeo AI** (API URL and API key). This filter does not store the key itself. It calls `local_dixeo`, which talks to the Dixeo API.

If the key is missing, the account has no credits, or the content image policy disables generate or edit, those actions do not run. The filter still installs and manual crop and upload remain available.

## Installation

1. Install and upgrade `local_dixeo` first.
2. Install the release ZIP `filter_dixeo_imageeditor-<release>.zip` under **Site administration → Plugins → Install plugins**, or copy the `dixeo_imageeditor` directory to `/filter/dixeo_imageeditor/`.
3. Enable the filter under **Site administration → Plugins → Filters → Manage filters**.

No Composer install step is required.

## Capabilities

| Capability | Description | Default roles |
|------------|-------------|---------------|
| `filter/dixeo_imageeditor:edit` | Edit embedded content images | Manager, Editing teacher |

## Privacy

The filter stores version-history metadata (filename, source, the user who saved the version, and the time) and archived image files.

Generate and AI edit transfer the prompt and image bytes to the Dixeo API through `local_dixeo`. That external location is declared in `classes/privacy/provider.php`.

## Source code

- **Repository:** https://github.com/dixeo/moodle-filter_dixeo_imageeditor
- **Bug tracker:** https://github.com/dixeo/moodle-filter_dixeo_imageeditor/issues

## Databases

Tables are created with Moodle’s XMLDB and Data Manipulation API. Automated tests run on MariaDB and PostgreSQL.

# Support

For documentation, licensing or technical support:

**Dixeo**

https://www.dixeo.com

support@dixeo.com

# License

Copyright © Dixeo

Licensed under the GNU General Public License v3.0 or later. Cropper.js is bundled under the MIT licence and declared in `thirdpartylibs.xml`.
