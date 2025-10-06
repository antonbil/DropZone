# Custom DropZone Uploader & Manager WordPress Plugin

A flexible and configurable WordPress plugin that provides file upload and management functionality through shortcodes. The plugin is designed to manage multiple, independent "dropzone" instances from a central `JSON` file, each with its own validation rules, upload locations, and user interface settings.

## Table of Contents
- [Features](#features)
- [Plugin Structure](#plugin-structure)
- [Installation](#installation)
- [Configuration (`dropzones.json`)](#configuration-dropzonesjson)
  - [Basic Structure](#basic-structure)
  - [Configuration Parameters](#configuration-parameters)
    - [Top-Level Parameters](#top-level-parameters)
    - [`file_rules`](#file_rules)
    - [`ui_texts`](#ui_texts)
    - [`override_config`](#override_config)
- [Usage](#usage)
- [JavaScript Files](#javascript-files)
- [Example Configuration](#example-configuration)

## Features

*   **Centralized Configuration:** Manage all dropzone instances from a single `config/dropzones.json` file.
*   **Dynamic Shortcodes:** The plugin automatically generates two shortcodes for each configuration entry:
    1.  An **Uploader Shortcode** for uploading files via a drag-and-drop interface.
    2.  A **Manager Shortcode** for viewing, renaming, and deleting already uploaded files.
*   **Extensive Validation:** Configure specific rules per dropzone for filenames (using regex), MIME types, and target directories.
*   **Dynamic Paths:** Target directories for uploads can be created dynamically based on date and time patterns (e.g., `Y/m`).
*   **Customizable Interface:** Modify the text and labels of the upload and management interfaces through the JSON configuration.
*   **Robust Architecture:** Built with an object-oriented approach (PHP classes) for maintainability and extensibility.

## Plugin Structure

The plugin follows a logical directory structure to keep its components organized.


/custom-dropzone-plugin/
├── custom-dropzone-plugin.php      # Hoofd-pluginbestand, laadt alles.
├── class-dropzone.php              # Bevat de kernklassen (FileUtils, DropZoneUploader, DropZoneManager).
├── config/
│   └── dropzones.json              # Het centrale configuratiebestand.
├── js/
│   ├── ovd-uploader.js             # Voorbeeld JS voor de 'ovd' uploader.
│   └── ovd-manager.js              # Voorbeeld JS voor de 'ovd' manager.
│   └── (andere JS-bestanden...)
└── README.md                       # Deze documentatie.
## Installation

1.  Place the entire `custom-dropzone-plugin` directory into the `/wp-content/plugins/` directory of your WordPress installation.
2.  Go to the "Plugins" screen in your WordPress dashboard.
3.  Find "Custom DropZone Uploader" in the list and click "Activate".
4.  Ensure that the `config/dropzones.json` file exists and is correctly formatted.

## Configuration (`dropzones.json`)

This is the heart of the plugin. It is an array of JSON objects, where each object defines a complete dropzone instance (uploader + manager).

### Basic Structure

[
    {
        "slug": "unieke_naam_1",
        "file_rules": { ... },
        "ui_texts": { ... },
        "override_config": { ... }
    },
    {
        "slug": "unieke_naam_2",
        "file_rules": { ... }
    }
]
### Configuration Parameters

#### Top-Level Parameters

| Parameter | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| `slug` | string | Yes | A unique, short name without spaces (e.g., `ovd`, `newsletter`). Used to generate default shortcodes and JS filenames. |
| `file_rules` | object | Yes | An object defining the validation and path rules for files. See below. |
| `ui_texts` | object | No | An object containing text strings for the user interface. |
| `override_config` | object | No | An object to override default generated settings (like shortcode names). |

#### `file_rules`

Defines how files are validated and where they are stored.

| Parameter | Type | Default (Example) | Description |
| :--- | :--- | :--- | :--- |
| `target_subdir_pattern` | string | The `slug` | The subdirectory within `wp-content/uploads/` where files are stored. Supports `date_i18n` formats. |
| `list_files_glob_pattern` | string | `*slug*.pdf` | The pattern (`glob`) used to find files for the manager view. |
| `filename_keyword_ensure` | string | The `slug` | A keyword that is automatically added to the filename if absent. Set to `null` to disable. |
| `filename_keyword_suffix` | string | `_slug` | The suffix used when `filename_keyword_ensure` is applied. |
| `name_pattern_prefix_regex` | string | `/.*/` | A regular expression that the start of the filename must match. |
| `name_pattern_keyword_regex`| string | `/slug/i` | A regular expression that the filename must match (typically to check for the keyword). |
| `name_pattern_extension_regex` | string | `/\.pdf$/i` | A regular expression that validates the allowed file extension(s). |
| `allowed_mime_types` | array | `['application/pdf']` | An array of allowed MIME types for uploads. |

#### `ui_texts`

Customizes the text in the frontend.

| Parameter | Type | Description |
| :--- | :--- | :--- |
| `main_instruction` | string | The main instruction text in the upload box. |
| `button_text` | string | The text on the "Select File" button. |
| `section_title` | string | The title above the file list in the manager. `%s` is replaced with the subdirectory name. |
| `no_files_message` | string | The message displayed when no files are found. |

#### `override_config`

Overrides default generated configuration values. This is for advanced use.

"override_config": {
    "uploader": {
        "shortcode_tag": "mijn_custom_uploader_shortcode",
        "ajax_action": "mijn_unieke_upload_actie",
        "js_handle": "mijn-custom-js-handle"
    },
    "manager": {
        "shortcode_tag": "mijn_custom_manager_shortcode",
        "capability_manage": "edit_posts"
    }
}
## Usage

After adding a configuration with a `slug` of, for example, `"sermon_notes"`, the plugin generates the following shortcodes:

*   `[sermon_notes_uploader]` - Displays the drag-and-drop upload interface.
*   `[sermon_notes_manager]` - Displays the file list and management options.

If you have overridden the `shortcode_tag` in the `override_config`, use that name instead. Place the desired shortcode on a page, post, or in a widget.

## JavaScript Files

For each `slug`, the plugin expects two corresponding JavaScript files to exist in the `/js/` directory:

1.  `[slug]-uploader.js`
2.  `[slug]-manager.js`

These files contain the logic for the frontend interaction. The plugin loads them automatically when their corresponding shortcode is used.

## Example Configuration
[
    {
        "slug": "ovd",
        "file_rules": {
            "target_subdir_pattern": "ordediensten/Y/m",
            "name_pattern_prefix_regex": "/^\\d{6}/",
            "name_pattern_keyword_regex": "/ovd/i",
            "list_files_glob_pattern": "*.pdf"
        },
        "ui_texts": {
            "main_instruction": "Sleep hier een Orde van Dienst (JJMMDD_..._ovd.pdf) naartoe.",
            "section_title": "Overzicht Ordes van Dienst voor de map: %s"
        },
        "override_config": {
            "manager": {
                "shortcode_tag": "overzicht_ordediensten"
            }
        }
    },
    {
        "slug": "nieuwsbrief",
        "file_rules": {
            "target_subdir_pattern": "nieuwsbrieven",
            "list_files_glob_pattern": "NL_*.{pdf,docx}",
            "name_pattern_prefix_regex": "/^NL_/",
            "name_pattern_extension_regex": "/\\.(pdf|docx)$/i",
            "allowed_mime_types": [
                "application/pdf",
                "application/vnd.openxmlformats-officedocument.wordprocessingml.document"
            ],
            "filename_keyword_ensure": null
        },
        "ui_texts": {
            "main_instruction": "Sleep hier de nieuwe Nieuwsbrief (NL_....pdf/docx)"
        }
    }
]
