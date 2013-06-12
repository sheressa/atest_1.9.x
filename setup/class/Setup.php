<?php

/**
 *
 * @author Matthew McNaney <mcnaney at gmail dot com>
 * @license http://opensource.org/licenses/lgpl-3.0.html
 */
define('SETUP_JQUERY', 'jquery-2.0.2.min.js');

class Setup {

    private $page_title;
    private $template;
    private $content;
    private $toolbar;
    private $title;
    private $install;
    private $javascript_file;

    public function __construct()
    {
        Session::start();
        $this->javascript_file[] = '<script src="' . PHPWS_SOURCE_HTTP . 'setup/javascript/' . SETUP_JQUERY . '"></script>';
    }

    public function display()
    {
        $message = \Message::get();
        if (!empty($message)) {
            $variables['message'] = $message;
        }
        if (!empty($this->toolbar)) {
            $variables['toolbar'] = $this->toolbar;
        }
        $variables['content'] = $this->content;
        if (!empty($this->title)) {
            $variables['page_title'] = $this->title . ' - ' . t('phpWebSite Setup');
            $variables['title'] = $this->title;
        } else {
            $variables['page_title'] = t('phpWebSite Setup');
        }

        $variables['javascript'] = $this->getJavascriptFile();
        echo new Template($variables, 'setup/templates/index.html');
    }

    public function isAdminLoggedIn()
    {
        return isset(Session::singleton()->admin_logged_in);
    }

    public function addJavascriptFile($javascript)
    {
        $this->javascript_file[] = '<script src="' . PHPWS_SOURCE_HTTP . 'setup/javascript/' . $javascript . '"></script>';
    }

    private function getJavascriptFile()
    {
        if (!empty($this->javascript_file)) {
            return implode("\n", $this->javascript_file);
        }
    }

    public function login()
    {
        if (!is_file(SETUP_CONFIGURATION_DIRECTORY . 'setup_config.php')) {
            $this->createSetupConfiguration();
            return;
        }

        // set in the setup_config.php file.
        $password = $username = null;
        include SETUP_CONFIGURATION_DIRECTORY . 'setup_config.php';
        $request = Server::getCurrentRequest();
        $form = new Form;
        $form->addHidden('sec', 'login');
        $user = $form->addTextField('username');
        $pass = $form->addPassword('password');

        $user->setLabel(t('Username'));
        $pass->setLabel(t('Password'));
        $form->addSubmit('submit', t('Log In'));
        $this->content = $form->printTemplate('setup/templates/forms/login.html');

        if ($request->isVar('username')) {
            $username_compare = $request->getVar('username');
            if (!$request->isVar('password')) {
                throw new Exception(t('Password blank'), SETUP_USER_ERROR);
            }
            $password_compare = $request->getVar('password');
            if (empty($password)) {
                throw new Exception(t('Password blank'), SETUP_USER_ERROR);
            }

            if ($username_compare == $username && $password_compare == $password) {
                Session::singleton()->admin_logged_in = 1;
                header('location: index.php');
                exit();
            }
            throw new Exception(t('Incorrect user name and/or password.'),
            SETUP_USER_ERROR);
        }
    }

    private function createSetupConfiguration()
    {
        $content = new Variable\Arr;
        $content->push('Since this is your first time here, we need to create a setup configuration file.');
        if (!is_writable(SETUP_CONFIGURATION_DIRECTORY)) {
            $content->push('Please make your ' . SETUP_CONFIGURATION_DIRECTORY . ' directory writable.');
            $this->content = $content->implodeTag('<p>');
            return;
        }

        $password = randomString(10);

        $config_body = file_get_contents('setup/templates/setup_config.txt');
        $config_save = "<?php\n" . str_replace('xxx', $password, $config_body) . "\n?>";
        file_put_contents(SETUP_CONFIGURATION_DIRECTORY . 'setup_config.php',
                $config_save);
        $content->push('<strong>Your configuration file has been saved.</strong> Look for a setup_config.php in your ' . SETUP_CONFIGURATION_DIRECTORY . ' directory to get a user name and password to log in.');
        $content->push('We recommend you change both the user name and password.');
        $content->push('You can change the setup_config.php file location by altering the SETUP_CONFIGURATION_DIRECTORY define in the setup/index.php file.');
        $content->push('<a href="index.php">Log in using new user name and password</a>.');
        $this->content = $content->implodeTag('<p>');
    }

    public function processCommand()
    {
        if (!$this->isAdminLoggedIn()) {
            $this->login();
            return;
        }

        $request = Server::getCurrentRequest();
        if ($request->isPost()) {
            $this->post();
        } else {
            $this->get();
        }
    }

    private function get()
    {
        $request = Server::getCurrentRequest();

        if (!is_file('config/core/config.php')) {
            $section = 'install';
        } elseif ($request->isVar('sec')) {
            $section = $request->getVar('sec');
        } else {
            $section = 'dashboard';
        }

        switch ($section) {
            case 'install':
                $this->loadInstall();
                $this->install->get();
                break;
            case 'dashboard':
                $this->loadToolbar();
                $this->dashboard();
                break;
        }
    }

    private function loadInstall()
    {
        require_once 'setup/class/Install.php';
        $this->install = new Install($this);
    }

    private function postConfigForm()
    {
        $request = Server::getCurrentRequest();
    }

    private function dashboard()
    {

    }

    private function loadToolbar()
    {

    }

    public function setTitle($title)
    {
        $this->title = $title;
    }

    private function post()
    {
        $request = Server::getCurrentRequest();
        if (!$request->isVar('sec')) {
            throw new Exception(t('Missing setup command'));
        }
        $section = $request->getVar('sec');

        switch ($section) {
            case 'install':
                $this->loadInstall();
                $this->install->post();
                break;
        }
    }

    public function setMessage($message)
    {
        \Message::set($message);
    }

    public function setContent($content)
    {
        $this->content = $content;
    }

}

?>
