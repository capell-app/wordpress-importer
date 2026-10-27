<?php

declare(strict_types=1);

return [
    'simplexml' => [
        'label' => 'WordPress Importer: XML parser available',
        'passed' => 'The SimpleXML extension required for WordPress WXR parsing is loaded.',
        'failed' => 'The SimpleXML extension required for WordPress WXR parsing is not loaded.',
        'remediation' => 'Install and enable the PHP SimpleXML extension before importing WordPress WXR exports.',
    ],
    'reader' => [
        'label' => 'WordPress Importer: WXR reader available',
        'passed' => 'The WordPress WXR reader is registered with Migration Assistant for XML imports.',
        'failed' => 'The WordPress WXR reader is not registered with Migration Assistant.',
        'remediation' => 'Ensure WordPressImporterServiceProvider is loaded after Migration Assistant and registers the WxrReader.',
    ],
    'contract' => [
        'label' => 'WordPress Importer: import reader compatible',
        'passed' => 'The WordPress WXR reader implements the Migration Assistant source-reader contract.',
        'failed' => 'The WordPress WXR reader no longer implements the Migration Assistant source-reader contract.',
        'remediation' => 'Update WxrReader to implement the current Migration Assistant ImportSourceReader contract.',
    ],
];
