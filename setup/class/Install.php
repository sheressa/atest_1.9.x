<?php

/**
 *
 * @author Matthew McNaney <mcnaney at gmail dot com>
 * @license http://opensource.org/licenses/lgpl-3.0.html
 */
class Install {

    /**
     * @var Setup
     */
    private $setup;
    private $dsn;

    public function __construct(Setup $setup)
    {
        $this->setup = $setup;
        $this->dsn = new \Database\DSN('mysql', '');
    }

    public function get()
    {
        $request = Server::getCurrentRequest();

        if ($request->isVar('js') && $request->isAjax()) {

        }

        $op = $request->isVar('op') ? $request->getVar('op') : 'prompt';

        switch ($op) {
            case 'prompt':
                $this->setup->setTitle(t('New installation'));
                $this->setup->setContent('<p>You do not have a config/core/config.php file. This must be a new installation.</p>
                    <p style="padding:8px"><a href="index.php?command=install&op=config_form" class="btn-large btn-primary">Install phpWebSite</a></p>');
                break;

            case 'config_form':
                $this->setup->setTitle(t('Create database configuration file'));
                $this->getConfigForm();
                break;
        }
    }

    public function post()
    {
        $request = Server::getCurrentRequest();

        if ($request->isVar('js') && $request->isAjax()) {
            switch ($request->getVar('js')) {
                case 'dblogin':
                    $json = $this->DBLogin();
                    break;

                default:
                    $json['error'] = 'Command not known';
            }
            echo json_encode($json);
            exit();
        }

        if (!$request->isVar('op')) {
            throw new Exception(t('Missing post operation'));
        }

        switch ($request->getVar('op')) {

        }
    }

    private function DBLogin()
    {
        try {
            // First post the values, see if they are legit
            $this->postDSNValues();

            // Next try and connect to the database.
            $db = \Database::newDB($this->dsn);

            $json['content'] = 'So far so good';
        } catch (\Exception $e) {
            $json['error'] = t('Could not log in because: %s', $e->getMessage());
        }
        return $json;
    }

    private function postDSNValues()
    {
        $request = Server::getCurrentRequest();

        $this->dsn->setDatabaseType($request->getVar('database_type'));
        //$this->dsn->setDatabaseName($request->getVar('database_name'));
        $this->dsn->setUsername($request->getVar('database_user'));
        $this->dsn->setPassword($request->getVar('database_password'));
        $this->dsn->setHost($request->getVar('database_host'));
        if (!$request->isEmpty('database_port')) {
            $this->dsn->setPort($request->getVar('database_port'));
        }
    }

    private function getForm()
    {
        $form = new Form;
        $form->addHidden('sec', 'install');
        return $form;
    }

    private function getConfigForm()
    {
        $request = \Server::getCurrentRequest();
        $form = $this->getForm();
        $form->setId('database-config');
        $form->addHidden('op', 'post_config');

        $types['mysql'] = $form->addRadio('database_type', 'mysql', 'MySQL');
        $types['pgsql'] = $form->addRadio('database_type', 'pgsql', 'PostgreSQL');

        $types[$this->dsn->getDatabaseType()->__toString()]->setSelection(true);

        //$form->addTextField('database_name', $request->getVar('database_name', ''))->setRequired();
        $form->addTextField('database_user')->setRequired();
        $form->addPassword('database_password');
        $form->addTextField('database_host');
        $form->addTextField('database_port');
        $form->addSubmit('save', t('Login in to database'));

        $this->setup->addJavascriptFile('validate/jquery.validate.min.js');
        $this->setup->addJavascriptFile('install/database.js');
        $this->setup->setTitle(t('Create your database configuration file'));
        $this->setup->setContent($form->printTemplate('setup/templates/forms/dbconfig.html'));
    }

}

?>
