# Custom DropZone Uploader & Manager WordPress Plugin

Een flexibele en configureerbare WordPress-plugin die via shortcodes upload- en beheerfunctionaliteit biedt. De plugin is ontworpen om via een centraal `JSON`-bestand meerdere, onafhankelijke "dropzone"-instanties te beheren, elk met eigen validatieregels, uploadlocaties en interface-instellingen.

## Inhoudsopgave
- [Functionaliteiten](#functionaliteiten)
- [Structuur van de Plugin](#structuur-van-de-plugin)
- [Installatie](#installatie)
- [Configuratie (`dropzones.json`)](#configuratie-dropzonesjson)
  - [Basisstructuur](#basisstructuur)
  - [Configuratieparameters](#configuratieparameters)
    - [Hoofdparameters](#hoofdparameters)
    - [`file_rules`](#file_rules)
    - [`ui_texts`](#ui_texts)
    - [`override_config`](#override_config)
- [Gebruik](#gebruik)
- [JavaScript Bestanden](#javascript-bestanden)
- [Voorbeeldconfiguratie](#voorbeeldconfiguratie)

## Functionaliteiten

*   **Centrale Configuratie:** Beheer alle dropzone-instanties vanuit één enkel `config/dropzones.json` bestand.
*   **Dynamische Shortcodes:** De plugin genereert automatisch twee shortcodes per configuratie-entry:
    1.  Een **Uploader Shortcode** voor het uploaden van bestanden via een drag-and-drop interface.
    2.  Een **Manager Shortcode** voor het bekijken, hernoemen en verwijderen van reeds geüploade bestanden.
*   **Uitgebreide Validatie:** Configureer per dropzone specifieke regels voor bestandsnamen (met regex), MIME-types, en doelmappen.
*   **Dynamische Paden:** Doelmappen voor uploads kunnen dynamisch worden aangemaakt op basis van datum- en tijdpatronen (bijv. `Y/m`).
*   **Aanpasbare Interface:** Wijzig de teksten en labels van de upload- en beheerinterfaces via de JSON-configuratie.
*   **Robuuste Architectuur:** Gebouwd met een objectgeoriënteerde aanpak (PHP-klassen) voor onderhoudbaarheid en uitbreidbaarheid.

## Structuur van de Plugin

De plugin volgt een logische mappenstructuur om de verschillende onderdelen georganiseerd te houden.

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
## Installatie

1.  Plaats de volledige `custom-dropzone-plugin` map in de `/wp-content/plugins/` directory van je WordPress-installatie.
2.  Ga naar het "Plugins" scherm in je WordPress-dashboard.
3.  Zoek "Custom DropZone Uploader" in de lijst en klik op "Activeren".
4.  Zorg ervoor dat het `config/dropzones.json` bestand bestaat en correct is geformatteerd.

## Configuratie (`dropzones.json`)

Dit is het hart van de plugin. Het is een array van JSON-objecten, waarbij elk object een complete dropzone-instantie (uploader + manager) definieert.

### Basisstructuur

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
### Configuratieparameters

#### Hoofdparameters

| Parameter | Type   | Verplicht | Beschrijving                                                                                                              |
| :-------- | :----- | :--------- | :------------------------------------------------------------------------------------------------------------------------ |
| `slug`    | string | Ja         | Een unieke, korte naam zonder spaties (bv. `ovd`, `nieuwsbrief`). Wordt gebruikt om standaard shortcodes en JS-namen te maken. |
| `file_rules` | object | Ja         | Een object dat de validatie- en padregels voor bestanden definieert. Zie hieronder.                                       |
| `ui_texts`   | object | Nee        | Een object met teksten voor de gebruikersinterface.                                                                       |
| `override_config` | object | Nee        | Een object om standaard gegenereerde instellingen (zoals shortcode-namen) te overschrijven.                          |

#### `file_rules`

Definieert hoe bestanden worden gevalideerd en waar ze worden opgeslagen.

| Parameter                   | Type   | Standaardwaarde (voorbeeld)                                  | Beschrijving                                                                                              |
| :-------------------------- | :----- | :----------------------------------------------------------- | :-------------------------------------------------------------------------------------------------------- |
| `target_subdir_pattern`     | string | De `slug`                                                    | De submap binnen `wp-content/uploads/` waar bestanden worden opgeslagen. Ondersteunt `date_i18n` formaten. |
| `list_files_glob_pattern`   | string | `*slug*.pdf`                                                 | Het patroon (`glob`) dat wordt gebruikt om bestanden in de manager-weergave te vinden.                   |
| `filename_keyword_ensure`   | string | De `slug`                                                    | Een trefwoord dat (indien afwezig) automatisch aan de bestandsnaam wordt toegevoegd. `null` om uit te schakelen. |
| `filename_keyword_suffix`   | string | `_slug`                                                      | Het achtervoegsel dat wordt gebruikt als `filename_keyword_ensure` wordt toegepast.                       |
| `name_pattern_prefix_regex` | string | `/.*/`                                                       | Een reguliere expressie waaraan het begin van de bestandsnaam moet voldoen.                               |
| `name_pattern_keyword_regex`| string | `/slug/i`                                                    | Een reguliere expressie waaraan de bestandsnaam moet voldoen (meestal om het trefwoord te controleren).   |
| `name_pattern_extension_regex` | string | `/\.pdf$/i`                                                  | Een reguliere expressie die de toegestane bestandsextensie(s) valideert.                                |
| `allowed_mime_types`        | array  | `['application/pdf']`                                        | Een array van toegestane MIME-types voor uploads.                                                         |

#### `ui_texts`

Past de teksten in de frontend aan.

| Parameter            | Type   | Beschrijving                                         |
| :------------------- | :----- | :--------------------------------------------------- |
| `main_instruction`   | string | De hoofdinstructie in het upload-vak.                |
| `button_text`        | string | De tekst op de "Selecteer Bestand" knop.             |
| `section_title`      | string | De titel boven de bestandenlijst in de manager. `%s` wordt vervangen door de submapnaam. |
| `no_files_message`   | string | Het bericht dat wordt getoond als er geen bestanden zijn gevonden. |

#### `override_config`

Overschrijft standaard gegenereerde configuratiewaarden. Dit is voor geavanceerd gebruik.
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
## Gebruik

Nadat je een configuratie met een `slug` van bijvoorbeeld `"ovd"` hebt toegevoegd, genereert de plugin de volgende shortcodes:

*   `[ovd_uploader]` - Toont de drag-and-drop upload-interface.
*   `[ovd_manager]` - Toont de lijst met bestanden en beheeropties.

Als je de `shortcode_tag` hebt overschreven in de `override_config`, gebruik dan die naam. Plaats de gewenste shortcode op een pagina, bericht of in een widget.

## JavaScript Bestanden

Voor elke `slug` verwacht de plugin dat er twee corresponderende JavaScript-bestanden bestaan in de `/js/` map:

1.  `[slug]-uploader.js`
2.  `[slug]-manager.js`

Deze bestanden bevatten de logica voor de frontend-interactie. De plugin laadt deze automatisch wanneer de bijbehorende shortcode wordt gebruikt.

## Voorbeeldconfiguratie

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
