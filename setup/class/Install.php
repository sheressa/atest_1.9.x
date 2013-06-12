<?php

/**
 *
 * @author Matthew McNaney <mcnaney at gmail dot com>
 * @license http://opensource.org/licenses/lgpl-3.0.html
 */
class Install {

    /**
     *
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
        if (!$request->isVar('op')) {
            throw new Exception(t('Missing post operation'));
        }

        switch ($request->getVar('op')) {
            case 'post_config':
                $this->postConfig();
                break;
        }
    }

    private function postDSNValues()
    {
        $request = Server::getCurrentRequest();

        $this->dsn->setDatabaseType($request->getVar('database_type'));
        //$this->dsn->setDatabaseName($request->getVar('database_name'));
        $this->dsn->setUsername($request->getVar('database_user'));
        if ($request->isVar('database_password', true)) {
            $this->dsn->setPassword($request->getVar('database_password'));
        }
        if ($request->isVar('database_host', true)) {
            $this->dsn->setHost($request->getVar('database_host'));
        }
        if ($request->isVar('database_port', true)) {
            $this->dsn->setPort($request->getVar('database_port'));
        }
    }

    private function postConfig()
    {
        try {
            $this->postDSNValues();
        } catch (Exception $exc) {
            $this->setup->setMessage(t('One or more of your configuration settings is incorrect.'));
        }

        try {
            $db = \Database::newDB($this->dsn);
        } catch (Exception $exc) {

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
        $form->addTextField('table_prefix');
        $form->addSubmit('save', t('Create database file'));
        $this->setup->addJavascriptFile('install/database.js');
        $this->setup->setTitle(t('Create your database configuration file'));
        $this->setup->setContent($form->printTemplate('setup/templates/forms/dbconfig.html'));
    }

}

?>
