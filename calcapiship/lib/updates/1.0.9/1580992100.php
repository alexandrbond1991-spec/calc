<?php
try {
    $file = wa()->getConfig()->getRootPath() . '/wa-plugins/shipping/calcapiship/lib/classes';
    if (file_exists($file)) {
        waFiles::delete($file, true);
    }
} catch (Exception $e) {
    waLog::dump(array(
        'error' => $e->getMessage()
    ), 'wa-plugins/shipping/calcapiship/update.log');
}
