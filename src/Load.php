<?php
/******************************************************************************
 * Copyright (c) 2017. Kitrix Team                                            *
 * Kitrix is open source project, available under MIT license.                *
 *                                                                            *
 * @author: Konstantin Perov <fe3dback@yandex.ru>                             *
 * Documentation:                                                             *
 * @see https://kitrix-org.github.io/docs                                     *
 *                                                                            *
 *                                                                            *
 ******************************************************************************/

namespace Kitrix;

use Bitrix\Main\Config\Configuration;
use Kitrix\Common\Kitx;
use Kitrix\Common\SingletonClass;
use Kitrix\MVC\Router;
use Kitrix\Hooks\BitrixAdmin;
use Kitrix\Plugins\PluginsManager;
use Whoops\Handler\PrettyPageHandler;
use Whoops\Run;

const DS = DIRECTORY_SEPARATOR;

/**
 * Auto loader
 *
 * Class Load
 * @package Kitrix
 */
final class Load
{
    const KITRIX_STORE = "local";
    const KITRIX_ROOT_PATH = self::KITRIX_STORE . DS . "kitrix";
    const KITRIX_PLUGINS_PATH = self::KITRIX_ROOT_PATH . DS . "plugins";
    const KITRIX_CONFIG_PATH = self::KITRIX_ROOT_PATH . DS . "db";
    const KITRIX_TMP_PATH = self::KITRIX_ROOT_PATH . DS . "tmp";

    use SingletonClass;

    /** @var bool - App run in debug mode? */
    private $debugModeOn = false;

    /** @var Router */
    private $router;

    /** @var bool */
    private $isInitialized = false;

    /**
     * Entry point for kitrix app
     * This actual load all kitrix
     * plugins, resolve dependencies
     * build routes and inject into Bitrix
     * admin panel.
     */
    public function init() {

        if ($this->isInitialized) {
            return;
        }
        $this->isInitialized = true;

        try
        {
            // load exception handler
            $this->requireWhoopsLib();

            // self check
            $this->selfRepair();

            // init plugins (and core, actually core is plugin)
            PluginsManager::getInstance()->init();

            // this run only in admin panel
            if ($this->isAdminPanel())
            {
                // Build url router
                $this->router = Router::getInstance();
                $this->router->prepare($_REQUEST['to'] ?: "/");

                // Inject into Kitrix
                $bitrixHook = new BitrixAdmin();
                $bitrixHook->injectIntoBitrix();
            }
        }
        catch (\Exception $e) {

            // we can't break app will load
            // only log errors
            Kitx::logBootError($e);
        }
    }

    /** =================== API ======================= */

    /**
     * Return true if app in debug mode
     * @return bool
     */
    public function isDebugMode() {

        return (bool)$this->debugModeOn;
    }

    /**
     * Return true if we in admin panel
     * @return bool
     */
    public function isAdminPanel() {

        // check if url start with bitrix root variable
        // like /bitrix/admin

        $uri = explode('/', trim($_SERVER['REQUEST_URI'], '/'));

        if (count($uri)) {
            $pop = array_shift($uri);

            if ("/{$pop}" == BX_ROOT) {
                return true;
            }
        }

        return false;
    }

    /**
     * Do not call this method directly
     * @return string
     * @internal
     */
    public function adminEntryPoint() {

        /** @var \CMain $APPLICATION */
        global $APPLICATION;

        $halt = false;

        if ($this->router)
        {
            try
            {
                $this->router->execute();
            }
            catch (\Exception $e)
            {
                Kitx::logBootError($e);
                $halt = true;
            }
        }
        else
        {
            $halt = true;
        }

        if ($halt) {
            return Kitx::frmt(
                "Fatal kitrix boot error. Please check __KITRIX_BOOT_LOG_HALT.txt for details", []
            );
        }

        $APPLICATION->SetTitle("Kitrix");

        if ($this->router->isPageExist()) {
            $html = $this->router->getHtml();
        }
        else
        {
            $html = "404 - Kitrix admin page not found. Check routes!";
        }

        return $html;
    }

    /** ================== INTERNAL ====================== */

    /**
     * check important staff for normal env working
     */
    private function selfRepair() {

        $requiredFolders = [
            $_SERVER['DOCUMENT_ROOT'] . DS . self::KITRIX_ROOT_PATH,
            $_SERVER['DOCUMENT_ROOT'] . DS . self::KITRIX_CONFIG_PATH,
            $_SERVER['DOCUMENT_ROOT'] . DS . self::KITRIX_PLUGINS_PATH,
            $_SERVER['DOCUMENT_ROOT'] . DS . self::KITRIX_TMP_PATH
        ];

        foreach ($requiredFolders as $path) {

            if (!is_dir($path)) {
                $status = mkdir($path, 0775, true);
                if (!$status) {
                    throw new \Exception(Kitx::frmt("
                        Kitrix cannot create important directory for normal working.
                        Check access rights for this dir: '%s'
                    ", [$path]));
                }
            }
        }

        return true;
    }

    /**
     * Auto handling exception by external lib
     */
    private function requireWhoopsLib() {

        // highlight code errors
        $exceptionHandling = Configuration::getValue("exception_handling");
        if (is_array($exceptionHandling) && $exceptionHandling['debug']) {
            $this->debugModeOn = true;
        }

        if ($this->debugModeOn) {
            $whoops = new Run;
            $whoops->pushHandler(new PrettyPageHandler);
            $whoops->register();
        }
    }

}

