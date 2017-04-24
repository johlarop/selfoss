<?php

namespace helpers;

/**
 * Helper class for rendering template
 *
 * @copyright  Copyright (c) Tobias Zeising (http://www.aditu.de)
 * @license    GPLv3 (https://www.gnu.org/licenses/gpl-3.0.html)
 * @author     Tobias Zeising <tobias.zeising@aditu.de>
 */
class View {
    /** @var string current base url */
    public $base = '';

    /** @internal JS static resource type */
    const STATIC_RESOURCE_JS = 'js';
    /** @internal CSS static resource type */
    const STATIC_RESOURCE_CSS = 'css';

    public static $staticmtime = [
        self::STATIC_RESOURCE_JS => 0,
        self::STATIC_RESOURCE_CSS => 0
    ];
    public static $staticPrefix = 'all';

    /**
     * set global view vars
     */
    public function __construct() {
        $this->genMinified(self::STATIC_RESOURCE_JS);
        $this->genMinified(self::STATIC_RESOURCE_CSS);
        $this->base = $this->getBaseUrl();
        $this->genOfflineSW();
    }

    /**
     * Returns the base url of the page. If a base url was configured in the
     * config.ini this will be used. Otherwise base url will be generated by
     * globale server variables ($_SERVER).
     */
    public static function getBaseUrl() {
        $base = '';

        // base url in config.ini file
        if (strlen(trim(\F3::get('base_url'))) > 0) {
            $base = \F3::get('base_url');
            $length = strlen($base);
            if ($length > 0 && substr($base, $length - 1, 1) != '/') {
                $base .= '/';
            }
        } else { // auto generate base url
            $protocol = 'http';
            if ((isset($_SERVER['HTTPS']) && !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https') ||
                (isset($_SERVER['HTTP_HTTPS']) && $_SERVER['HTTP_HTTPS'] == 'https')) {
                $protocol = 'https';
            }

            // check for SSL proxy
            if (isset($_SERVER['HTTP_X_FORWARDED_SERVER']) && isset($_SERVER['HTTP_X_FORWARDED_HOST'])
            && ($_SERVER['HTTP_X_FORWARDED_SERVER'] === $_SERVER['HTTP_X_FORWARDED_HOST'])) {
                $subdir = '/' . preg_replace('/\/[^\/]+$/', '', $_SERVER['PHP_SELF']);
                $host = $_SERVER['HTTP_X_FORWARDED_SERVER'];
            } else {
                $subdir = \F3::get('BASE');
                $host = $_SERVER['SERVER_NAME'];
            }

            $port = '';
            if (($protocol == 'http' && $_SERVER['SERVER_PORT'] != '80') ||
                ($protocol == 'https' && $_SERVER['SERVER_PORT'] != '443')) {
                $port = ':' . $_SERVER['SERVER_PORT'];
            }
            //Override the port if nginx is the front end and the traffic is being forwarded
            if (isset($_SERVER['HTTP_X_FORWARDED_PORT'])) {
                $port = ':' . $_SERVER['HTTP_X_FORWARDED_PORT'];
            }

            $base = $protocol . '://' . $host . $port . $subdir . '/';
        }

        return $base;
    }

    /**
     * render template
     *
     * @param string $template file
     *
     * @return string rendered html
     */
    public function render($template) {
        ob_start();
        include $template;
        $content = ob_get_contents();
        ob_end_clean();

        return $content;
    }

    /**
     * send error message
     *
     * @param string $message
     *
     * @return void
     */
    public function error($message) {
        header('HTTP/1.0 400 Bad Request');
        die($message);
    }

    /**
     * send error message as json string
     *
     * @param mixed $datan
     *
     * @return void
     */
    public function jsonError($data) {
        header('Content-type: application/json');
        $this->error(json_encode($data));
    }

    /**
     * send success message as json string
     *
     * @param mixed $datan
     *
     * @return void
     */
    public function jsonSuccess($data) {
        header('Content-type: application/json');
        die(json_encode($data));
    }

    /**
     * returns max mtime for file paths given in array
     *
     * @param array $filePaths array of file paths
     *
     * @return int max mtime (unix timestamp)
     */
    public static function maxmtime(array $filePaths) {
        $maxmtime = 0;
        foreach ($filePaths as $filePath) {
            $filePath = explode('?', $filePath)[0]; // strip query string
            $fullPath = \F3::get('BASEDIR') . '/' . $filePath;

            if (!file_exists($fullPath)) {
                throw new \Exception("Missing file “${filePath}”. Did you install the dependencies using npm?");
            }

            $maxmtime = max($maxmtime, filemtime($fullPath));
        }

        return $maxmtime;
    }

    /**
     * returns global JavaScript file name (all.js)
     *
     * @return string all.js file name
     */
    public static function getGlobalJsFileName() {
        return self::$staticPrefix . '.js?v=' . self::$staticmtime[self::STATIC_RESOURCE_JS];
    }

    /**
     * returns global CSS file name (all.css)
     *
     * @return string all.css file name
     */
    public static function getGlobalCssFileName() {
        return self::$staticPrefix . '.css?v=' . self::$staticmtime[self::STATIC_RESOURCE_CSS];
    }

    /**
     * generate minified css and js
     *
     * @param string $type
     *
     * @return void
     */
    private function genMinified($type) {
        self::$staticmtime[$type] = self::maxmtime(\F3::get($type));

        if ($type == self::STATIC_RESOURCE_JS) {
            $filename = self::getGlobalJsFileName();
        } elseif ($type == self::STATIC_RESOURCE_CSS) {
            $filename = self::getGlobalCssFileName();
        }
        $target = \F3::get('BASEDIR') . '/public/' . self::$staticPrefix . '.' . $type;

        // build if needed
        if (!file_exists($target) || filemtime($target) < self::$staticmtime[$type]) {
            $minified = '';
            foreach (\F3::get($type) as $file) {
                if ($type == self::STATIC_RESOURCE_JS) {
                    $minifiedFile = $this->minifyJs(file_get_contents(\F3::get('BASEDIR') . '/' . $file));
                } elseif ($type == self::STATIC_RESOURCE_CSS) {
                    $minifiedFile = $this->minifyCss(file_get_contents(\F3::get('BASEDIR') . '/' . $file));
                }
                $minified = $minified . "\n" . $minifiedFile;
            }
            file_put_contents($target, $minified);
        }
    }

    /**
     * minifies javascript if DEBUG mode is disabled
     *
     * @param string $content javascript to minify
     *
     * @return string minified javascript
     */
    private function minifyJs($content) {
        if (\F3::get('DEBUG') != 0) {
            return $content;
        }

        return \JShrink\Minifier::minify($content);
    }

    /**
     * minifies css if DEBUG mode is disabled
     *
     * @param string $content css to minify
     *
     * @return string minified css
     */
    private function minifyCss($content) {
        if (\F3::get('DEBUG') != 0) {
            return $content;
        }

        return \CssMin::minify($content);
    }

    /**
     * List files according to globbing pattern from selfoss base.
     *
     * @param string relative globbing pattern
     *
     * @return array list of files paths relative to base
     */
    private static function ls($relativePattern) {
        $files = [];
        $absolutePattern = \F3::get('BASEDIR') . '/' . $relativePattern;
        $basePathLength = strlen(\F3::get('BASEDIR')) + 1;
        foreach (glob($absolutePattern) as $fn) {
            if ($fn[0] != '.') {
                $files[] = substr($fn, $basePathLength);
            }
        }

        return $files;
    }

    public static function offlineFiles() {
        $offlineFiles = array_merge([
                'public/' . self::getGlobalJsFileName(),
                'public/' . self::getGlobalCssFileName()
            ],
            self::ls('public/images/*')
        );

        return $offlineFiles;
    }

    public static function offlineMtime(array $offlineFiles) {
        $indirectResources = [
            'defaults.ini',
            'config.ini',
            'templates/home.phtml',
            'public/js/selfoss-sw-offline.js'
        ];

        return self::maxmtime(array_merge($offlineFiles, $indirectResources));
    }

    /**
     * Build the offline service worker source from static resources.
     *
     * @return void
     */
    public function genOfflineSW() {
        $offlineFiles = self::offlineFiles();
        $staticmtime = self::offlineMtime($offlineFiles);

        $target = \F3::get('BASEDIR') . '/public/selfoss-sw-offline.js';

        if (!file_exists($target) || filemtime($target) < $staticmtime) {
            $subdir = parse_url($this->base)['path'];

            $data = [
                'subdir' => $subdir,
                'version' => $staticmtime,
                'files' => [$subdir]
            ];

            foreach ($offlineFiles as $fn) {
                if (substr($fn, 0, 7) == 'public/') {
                    $fn = substr($fn, 7);
                }
                $data['files'][] = $subdir . $fn;
            }

            $offlineWorker = 'var offlineManifest = ';
            $offlineWorker .= json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $offlineWorker .= ";\n\n\n";
            $offlineWorker .= file_get_contents(\F3::get('BASEDIR') . '/public/js/selfoss-sw-offline.js');
            file_put_contents($target, $offlineWorker);
        }
    }
}
