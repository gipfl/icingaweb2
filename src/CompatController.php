<?php

namespace gipfl\IcingaWeb2;

use gipfl\IcingaWeb2\Controller\Extension\AutoRefreshHelper;
use gipfl\IcingaWeb2\Controller\Extension\ConfigHelper;
use gipfl\Translation\StaticTranslator;
use gipfl\Translation\TranslationHelper;
use gipfl\IcingaWeb2\Controller\Extension\ControlsAndContentHelper;
use gipfl\IcingaWeb2\Widget\ControlsAndContent;
use gipfl\IcingaWeb2\Zf1\SimpleViewRenderer;
use GuzzleHttp\Psr7\ServerRequest;
use Icinga\Application\Benchmark;
use Icinga\Application\Icinga;
use Icinga\Application\Modules\Manager;
use Icinga\Application\Modules\Module;
use Icinga\Authentication\Auth;
use Icinga\Exception\ProgrammingError;
use Icinga\File\Pdf;
use Icinga\Security\SecurityException;
use Icinga\User;
use Icinga\Web\Notification;
use Icinga\Web\Session;
use Icinga\Web\UrlParams;
use Icinga\Web\Url as WebUrl;
use Icinga\Web\Window;
use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Zend_Controller_Action;
use Zend_Controller_Action_HelperBroker as ZfHelperBroker;
use Zend_Controller_Request_Abstract as ZfRequest;
use Zend_Controller_Response_Abstract as ZfResponse;

/**
 * Class CompatController
 * @method \Icinga\Web\Request getRequest() {
 *     {@inheritdoc}
 *     @return  \Icinga\Web\Request
 * }
 *
 * @method \Icinga\Web\Response getResponse() {
 *     {@inheritdoc}
 *     @return  \Icinga\Web\Response
 * }
 */
class CompatController extends Zend_Controller_Action implements ControlsAndContent
{
    use AutoRefreshHelper;
    use ControlsAndContentHelper;
    use ConfigHelper;
    use TranslationHelper;

    /** @var bool  Whether the controller requires the user to be authenticated */
    protected $requiresAuthentication = true;

    protected $applicationName = 'Icinga Web';

    /** @var string The current module's name */
    private $moduleName;

    private $module;

    private $window;

    // https://github.com/joshbuchea/HEAD

    /** @var SimpleViewRenderer */
    protected $viewRenderer;

    /** @var bool */
    private $reloadCss = false;

    /** @var bool */
    private $rerenderLayout = false;

    /** @var string */
    private $xhrLayout = 'inline';

    /** @var \Zend_Layout */
    private $layout;

    /** @var string The inner layout (inside the body) to use */
    private $innerLayout = 'body';

    /**
     * Authentication manager
     *
     * @var Auth|null
     */
    private $auth;

    /** @var UrlParams */
    protected $params;

    /**
     * The constructor starts benchmarking, loads the configuration and sets
     * other useful controller properties
     *
     * @param ZfRequest $request
     * @param ZfResponse $response
     * @param array $invokeArgs Any additional invocation arguments
     * @throws SecurityException
     */
    public function __construct(
        ZfRequest $request,
        ZfResponse $response,
        array $invokeArgs = array()
    ) {
        /** @var \Icinga\Web\Request $request */
        /** @var \Icinga\Web\Response $response */
        $this->setRequest($request)
            ->setResponse($response)
            ->_setInvokeArgs($invokeArgs);

        $this->prepareViewRenderer();
        $this->_helper = new ZfHelperBroker($this);

        $this->handlerBrowserWindows();
        $this->initializeTranslator();
        $this->initializeLayout();

        $this->view->compact = $request->getParam('view') === 'compact';
        $url = $this->url();
        $this->params = $url->getParams();

        if ($url->shift('showCompact')) {
            $this->view->compact = true;
        }

        $this->checkPermissionBasics();
        Benchmark::measure('Ready to initialize the controller');
        $this->prepareInit();
        $this->init();
    }

    protected function initializeLayout()
    {
        $url = $this->url();
        $layout = $this->layout = $this->_helper->layout();
        $layout->isIframe = $url->shift('isIframe');
        $layout->showFullscreen = $url->shift('showFullscreen');
        $layout->moduleName = $this->getModuleName();
        if ($this->rerenderLayout = $url->shift('renderLayout')) {
            $this->xhrLayout = $this->innerLayout;
        }
        if ($url->shift('_disableLayout')) {
            $this->layout->disableLayout();
        }
    }

    /**
     * Prepare controller initialization
     *
     * As it should not be required for controllers to call the parent's init() method,
     * base controllers should use prepareInit() in order to prepare the controller
     * initialization.
     *
     * @see \Zend_Controller_Action::init() For the controller initialization method.
     */
    protected function prepareInit()
    {
    }

    /**
     * Return the current module's name
     *
     * @return  string
     */
    public function getModuleName()
    {
        if ($this->moduleName === null) {
            $this->moduleName = $this->getRequest()->getModuleName();
        }

        return $this->moduleName;
    }

    protected function setModuleName($name)
    {
        $this->moduleName = $name;

        return $this;
    }

    /**
     * Return this controller's module
     *
     * @return  Module
     * @codingStandardsIgnoreStart
     */
    public function Module()
    {
        // @codingStandardsIgnoreEnd
        if ($this->module === null) {
            try {
                $this->module = Icinga::app()->getModuleManager()->getModule($this->getModuleName());
            } catch (ProgrammingError $e) {
                throw new RuntimeException($e->getMessage(), $e->getCode(), $e);
            }
        }

        return $this->module;
    }

    /**
     * @return Window
     * @codingStandardsIgnoreStart
     */
    public function Window()
    {
        // @codingStandardsIgnoreEnd
        if ($this->window === null) {
            $this->window = new Window(
                $this->getRequestHeader('X-Icinga-WindowId', Window::UNDEFINED)
            );
        }
        return $this->window;
    }

    protected function handlerBrowserWindows()
    {
        if ($this->isXhr()) {
            $id = $this->getRequestHeader('X-Icinga-WindowId', Window::UNDEFINED);

            if ($id === Window::UNDEFINED) {
                $this->window = new Window($id);
                $this->_response->setHeader('X-Icinga-WindowId', Window::generateId());
            }
        }
    }

    /**
     * @return ServerRequestInterface
     */
    protected function getServerRequest()
    {
        return ServerRequest::fromGlobals();
    }

    protected function getRequestHeader($key, $default = null)
    {
        try {
            $value = $this->getRequest()->getHeader($key);
        } catch (\Zend_Controller_Request_Exception $e) {
            throw new InvalidArgumentException($e->getMessage(), $e->getCode(), $e);
        }

        if ($value === null) {
            return $default;
        } else {
            return $value;
        }
    }

    /**
     * @throws SecurityException
     */
    protected function checkPermissionBasics()
    {
        $request = $this->getRequest();
        // $auth->authenticate($request, $response, $this->requiresLogin());
        if ($this->requiresLogin()) {
            if (! $request->isXmlHttpRequest() && $request->isApiRequest()) {
                Auth::getInstance()->challengeHttp();
            }
            $this->redirectToLogin(Url::fromRequest());
        }
        if (($this->Auth()->isAuthenticated() || $this->requiresLogin())
            && $this->getFrontController()->getDefaultModule() !== $this->getModuleName()) {
            $this->assertPermission(Manager::MODULE_PERMISSION_NS . $this->getModuleName());
        }
    }

    protected function initializeTranslator()
    {
        $moduleName = $this->getModuleName();
        $domain = $moduleName !== 'default' ? $moduleName : 'icinga';
        $this->view->translationDomain = $domain;
        StaticTranslator::set(new Translator($domain));
    }

    public function init()
    {
        // Hint: we intentionally do not call our parent's init() method
    }

    /**
     * Get the authentication manager
     *
     * @return Auth
     * @codingStandardsIgnoreStart
     */
    public function Auth()
    {
        // @codingStandardsIgnoreEnd
        if ($this->auth === null) {
            $this->auth = Auth::getInstance();
        }
        return $this->auth;
    }

    /**
     * Whether the current user has the given permission
     *
     * @param   string  $permission Name of the permission
     *
     * @return  bool
     */
    public function hasPermission($permission)
    {
        return $this->Auth()->hasPermission($permission);
    }

    /**
     * Assert that the current user has the given permission
     *
     * @param   string  $permission     Name of the permission
     *
     * @throws  SecurityException       If the current user lacks the given permission
     */
    public function assertPermission($permission)
    {
        if (! $this->Auth()->hasPermission($permission)) {
            throw new SecurityException('No permission for %s', $permission);
        }
    }

    /**
     * Return restriction information for an eventually authenticated user
     *
     * @param   string  $name   Restriction name
     *
     * @return  array
     */
    public function getRestrictions($name)
    {
        return $this->Auth()->getRestrictions($name);
    }

    /**
     * Check whether the controller requires a login. That is when the controller requires authentication and the
     * user is currently not authenticated
     *
     * @return  bool
     */
    protected function requiresLogin()
    {
        if (! $this->requiresAuthentication) {
            return false;
        }

        return ! $this->Auth()->isAuthenticated();
    }

    public function prepareViewRenderer()
    {
        $this->viewRenderer = new SimpleViewRenderer();
        $this->viewRenderer->replaceZendViewRenderer();
        $this->view = $this->viewRenderer->view;
    }

    /**
     * @return SimpleViewRenderer
     */
    public function getViewRenderer()
    {
        return $this->viewRenderer;
    }

    protected function redirectXhr($url)
    {
        if (! $url instanceof WebUrl) {
            $url = Url::fromPath($url);
        }

        if ($this->rerenderLayout) {
            $this->getResponse()->setHeader('X-Icinga-Rerender-Layout', 'yes');
        }
        if ($this->reloadCss) {
            $this->getResponse()->setHeader('X-Icinga-Reload-Css', 'now');
        }

        $this->shutdownSession();

        $this->getResponse()
            ->setHeader('X-Icinga-Redirect', rawurlencode($url->getAbsoluteUrl()))
            ->sendHeaders();

        exit;
    }

    /**
     * Detect whether the current request requires changes in the layout and apply them before rendering
     *
     * @see Zend_Controller_Action::postDispatch()
     */
    public function postDispatch()
    {
        Benchmark::measure('Action::postDispatch()');

        $layout = $this->layout;
        $req = $this->getRequest();
        $layout->innerLayout = $this->innerLayout;

        /** @var User $user */
        if ($user = $req->getUser()) {
            if ((bool) $user->getPreferences()->getValue('icingaweb', 'show_benchmark', false)) {
                if ($layout->isEnabled()) {
                    $layout->benchmark = $this->renderBenchmark();
                }
            }

            if (! (bool) $user->getPreferences()->getValue('icingaweb', 'auto_refresh', true)) {
                $this->disableAutoRefresh();
            }
        }

        if ($req->getParam('format') === 'pdf') {
            $this->shutdownSession();
            $this->sendAsPdf();
            return;
        }

        if ($this->isXhr()) {
            $this->postDispatchXhr();
        }

        $this->shutdownSession();
    }

    public function postDispatchXhr()
    {
        $this->layout->setLayout($this->xhrLayout);
        $resp = $this->getResponse();

        $notifications = Notification::getInstance();
        if ($notifications->hasMessages()) {
            $notificationList = array();
            foreach ($notifications->popMessages() as $m) {
                $notificationList[] = rawurlencode($m->type . ' ' . $m->message);
            }
            $resp->setHeader('X-Icinga-Notification', implode('&', $notificationList), true);
        }

        if ($this->reloadCss) {
            $resp->setHeader('X-Icinga-CssReload', 'now', true);
        }

        if ($this->title) {
            if (preg_match('~[\r\n]~', $this->title)) {
                // TODO: Innocent exception and error log for hack attempts
                throw new InvalidArgumentException('No newlines allowed in page title');
            }
            $resp->setHeader(
                'X-Icinga-Title',
                // TODO: Config
                rawurlencode($this->title . ' :: ' . $this->applicationName),
                true
            );
        } else {
            // TODO: config
            $resp->setHeader('X-Icinga-Title', rawurlencode($this->applicationName), true);
        }

        if ($this->rerenderLayout) {
            $this->getResponse()->setHeader('X-Icinga-Container', 'layout', true);
        }

        if (isset($this->autorefreshInterval)) {
            $resp->setHeader('X-Icinga-Refresh', $this->autorefreshInterval, true);
        }

        if ($name = $this->getModuleName()) {
            $this->getResponse()->setHeader('X-Icinga-Module', $name, true);
        }
    }

    /**
     * Redirect to login
     *
     * XHR will always redirect to __SELF__ if an URL to redirect to after successful login is set. __SELF__ instructs
     * JavaScript to redirect to the current window's URL if it's an auto-refresh request or to redirect to the URL
     * which required login if it's not an auto-refreshing one.
     *
     * XHR will respond with HTTP status code 403 Forbidden.
     *
     * @param   Url|string  $redirect   URL to redirect to after successful login
     */
    protected function redirectToLogin($redirect = null)
    {
        $login = Url::fromPath('authentication/login');
        if ($this->isXhr()) {
            if ($redirect !== null) {
                $login->setParam('redirect', '__SELF__');
            }

            $this->_response->setHttpResponseCode(403);
        } elseif ($redirect !== null) {
            if (! $redirect instanceof Url) {
                $redirect = Url::fromPath($redirect);
            }

            if (($relativeUrl = $redirect->getRelativeUrl())) {
                $login->setParam('redirect', $relativeUrl);
            }
        }

        $this->rerenderLayout()->redirectNow($login);
    }

    protected function sendAsPdf()
    {
        $pdf = new Pdf();
        $pdf->renderControllerAction($this);
    }

    /**
     * Render the benchmark
     *
     * @return string Benchmark HTML
     */
    protected function renderBenchmark()
    {
        $this->viewRenderer->postDispatch();
        Benchmark::measure('Response ready');
        return Benchmark::renderToHtml()/*
            . '<pre style="height: 16em; vertical-overflow: scroll">'
            . print_r(get_included_files(), 1)
        . '</pre>'*/;
    }

    public function isXhr()
    {
        return $this->getRequest()->isXmlHttpRequest();
    }

    protected function redirectHttp($url)
    {
        if (! $url instanceof Url) {
            $url = Url::fromPath($url);
        }
        $this->shutdownSession();
        $this->_helper->Redirector->gotoUrlAndExit($url->getRelativeUrl());
    }

    /**
     *  Redirect to a specific url, updating the browsers URL field
     *
     *  @param Url|string $url The target to redirect to
     **/
    public function redirectNow($url)
    {
        if ($this->isXhr()) {
            $this->redirectXhr($url);
        } else {
            $this->redirectHttp($url);
        }
    }

    protected function shutdownSession()
    {
        $session = Session::getSession();
        if ($session->hasChanged()) {
            $session->write();
        }
    }

    protected function rerenderLayout()
    {
        $this->rerenderLayout = true;
        $this->xhrLayout = 'layout';
        return $this;
    }

    protected function reloadCss()
    {
        $this->reloadCss = true;
        return $this;
    }
}
