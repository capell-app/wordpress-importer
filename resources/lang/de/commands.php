<?php

declare(strict_types=1);

return [
    'import' => [
        'update_fields_invalid' => 'Unbekannte Aktualisierungsfelder: :fields. Verwenden Sie :allowed.',
        'taxonomy_names' => [
            'category' => 'Kategorien',
            'tag' => 'Schlagwörter',
        ],
        'actor_not_found' => 'Der ausgewählte Importbenutzer wurde nicht gefunden.',
        'target_site_not_found' => 'Die ausgewählte Import-Website wurde nicht gefunden.',
        'description' => 'Liest einen WordPress-WXR-Export und erstellt eine Migration-Assistant-Vorschau.',
        'path_required' => 'Ein Pfad zum WordPress-WXR-Export ist erforderlich.',
        'path_missing' => 'Der WordPress-Export [:path] wurde nicht gefunden.',
        'option_required' => 'Die Option :option ist erforderlich, wenn ein WordPress-WXR-Import ausgeführt wird.',
        'invalid_wxr' => 'Der WordPress-Export [:path] ist kein lesbarer WXR-XML-Export.',
        'target_label' => 'WordPress-WXR-Import',
        'read_summary' => 'Aus :filename gelesene WordPress-Zeilen: :count.',
        'omitted_summary' => 'Fehlerhafte Seiten- oder Beitragselemente, die in dieser unvollständigen Vorschau ausgelassen wurden: :count. Angezeigte Diagnosen: :shown. Reparieren Sie den Export und erstellen Sie die Vorschau erneut.',
        'item_omitted' => 'Element :item übersprungen: :reason',
        'missing_id' => 'wp:post_id fehlt',
        'missing_title' => 'Titel fehlt',
        'invalid_item' => 'ungültiges Element',
        'executed_summary' => 'Importierte Seiten: :pages. Importierte Seiten-URLs: :page_urls. Migration-Assistant-Sitzung: :session.',
        'duplicate_source_identity' => 'Übersprungen, weil :source bereits in Capell vorhanden ist.',
        'redirect_note' => 'Nach dem Import aus einem alten WordPress-WXR-Permalink erstellt.',
        'columns' => [
            'rows' => 'Zeilen',
            'creates' => 'Erstellt',
            'skips' => 'Übersprungen',
            'target' => 'Ziel',
        ],
    ],
];
