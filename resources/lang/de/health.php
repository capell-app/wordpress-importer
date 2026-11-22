<?php

declare(strict_types=1);

return [
    'simplexml' => [
        'label' => 'WordPress-Importer: XML-Parser verfügbar',
        'passed' => 'Die für das Parsen von WordPress-WXR erforderliche SimpleXML-Erweiterung ist geladen.',
        'failed' => 'Die für das Parsen von WordPress-WXR erforderliche SimpleXML-Erweiterung ist nicht geladen.',
        'remediation' => 'Installieren und aktivieren Sie die PHP-Erweiterung SimpleXML, bevor Sie WordPress-WXR-Exporte importieren.',
    ],
    'reader' => [
        'label' => 'WordPress-Importer: WXR-Reader verfügbar',
        'passed' => 'Der WordPress-WXR-Reader ist für XML-Importe bei Migration Assistant registriert.',
        'failed' => 'Der WordPress-WXR-Reader ist nicht bei Migration Assistant registriert.',
        'remediation' => 'Stellen Sie sicher, dass WordPressImporterServiceProvider nach Migration Assistant geladen wird und den WxrReader registriert.',
    ],
    'contract' => [
        'label' => 'WordPress-Importer: Import-Reader kompatibel',
        'passed' => 'Der WordPress-WXR-Reader implementiert den Source-Reader-Contract von Migration Assistant.',
        'failed' => 'Der WordPress-WXR-Reader implementiert den Source-Reader-Contract von Migration Assistant nicht mehr.',
        'remediation' => 'Aktualisieren Sie WxrReader, sodass er den aktuellen ImportSourceReader-Contract von Migration Assistant implementiert.',
    ],
];
