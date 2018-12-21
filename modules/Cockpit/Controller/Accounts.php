<?php

namespace Cockpit\Controller;

class Accounts extends \Cockpit\AuthController {

    public function index() {

        if (!$this->module('cockpit')->hasaccess('cockpit', 'accounts')) {
            return $this->helper('admin')->denyRequest();
        }

        $current  = $this->user['_id'];
        $groups   = $this->module('cockpit')->getGroups();

        return $this->render('cockpit:views/accounts/index.php', compact('current', 'groups'));
    }


    public function account($uid=null) {

        if (!$uid) {
            $uid = $this->user['_id'];
        }

        if (!$this->module('cockpit')->hasaccess('cockpit', 'accounts') && $uid != $this->user['_id']) {
            return $this->helper('admin')->denyRequest();
        }

        $account = $this->app->storage->findOne('cockpit/accounts', ['_id' => $uid]);

        if (!$account) {
            return false;
        }

        unset($account["password"]);

        $fields    = $this->app->retrieve('config/account/fields', null);
        $languages = $this->getLanguages();
        $groups    = $this->module('cockpit')->getGroups();

        return $this->render('cockpit:views/accounts/account.php', compact('account', 'uid', 'languages', 'groups', 'fields'));
    }

    public function create() {

        if (!$this->module('cockpit')->hasaccess('cockpit', 'accounts')) {
            return $this->helper('admin')->denyRequest();
        }

        $uid       = null;
        $account   = ['user'=>'', 'email'=>'', 'active'=>true, 'group'=>'admin', 'i18n'=>$this->app->helper('i18n')->locale];

        $fields    = $this->app->retrieve('config/account/fields', null);
        $languages = $this->getLanguages();
        $groups    = $this->module('cockpit')->getGroups();

        return $this->render('cockpit:views/accounts/account.php', compact('account', 'uid', 'languages', 'groups', 'fields'));
    }

    public function save() {

        if ($data = $this->param('account', false)) {

            // check rights
            if (!$this->module('cockpit')->hasaccess('cockpit', 'accounts')) {

                if (!isset($data['_id']) || $data['_id'] != $this->user['_id']) {
                    return $this->helper('admin')->denyRequest();
                }
            }

            $data['_modified'] = time();

            if (!isset($data['_id'])) {

                // new user needs a password
                if (!isset($data['password']) || !trim($data['password'])) {
                    return $this->stop(['error' => 'User password required'], 412);
                }

                if (!isset($data['user']) || !trim($data['user'])) {
                    return $this->stop(['error' => 'Username required'], 412);
                }

                $data['_created'] = $data['_modified'];
            }

            if (isset($data['group']) && !$this->module('cockpit')->hasaccess('cockpit', 'accounts')) {
                unset($data['group']);
            }

            if (isset($data['password'])) {

                if (strlen($data['password'])){
                    $data['password'] = $this->app->hash($data['password']);
                } else {
                    unset($data['password']);
                }
            }

            if (isset($data['email']) && !$this->helper('utils')->isEmail($data['email'])) {
                return $this->stop(['error' => 'Valid email required'], 412);
            }

            if (isset($data['user']) && !trim($data['user'])) {
                return $this->stop(['error' => 'Username cannot be empty!'], 412);
            }

            foreach (['name', 'user', 'email'] as $key) {
                if (isset($data[$key])) $data[$key] = strip_tags(trim($data[$key]));
            }

            // unique check
            // --
            if (isset($data['user'])) {

                $_account = $this->app->storage->findOne('cockpit/accounts', ['user'  => $data['user']]);

                if ($_account && (!isset($data['_id']) || $data['_id'] != $_account['_id'])) {
                    $this->app->stop(['error' =>  'Username is already used!'], 412);
                }
            }

            if (isset($data['email'])) {

                $_account = $this->app->storage->findOne('cockpit/accounts', ['email'  => $data['email']]);

                if ($_account && (!isset($data['_id']) || $data['_id'] != $_account['_id'])) {
                    $this->app->stop(['error' =>  'Email is already used!'], 412);
                }
            }
            // --

            $this->app->trigger('cockpit.accounts.save', [&$data, isset($data['_id'])]);
            $this->app->storage->save('cockpit/accounts', $data);

            if (isset($data['password'])) {
                unset($data['password']);
            }

            if ($data['_id'] == $this->user['_id']) {
                $this->module('cockpit')->setUser($data);
            }

            return json_encode($data);
        }

        return false;

    }

    public function remove() {

        if (!$this->module('cockpit')->hasaccess('cockpit', 'accounts')) {
            return $this->helper('admin')->denyRequest();
        }

        if ($data = $this->param('account', false)) {

            // user can't delete himself
            if ($data['_id'] != $this->user['_id']) {

                $this->app->storage->remove('cockpit/accounts', ['_id' => $data['_id']]);

                return '{"success":true}';
            }
        }

        return false;
    }

    public function find() {

        $options = array_merge([
            'sort'   => ['user' => 1]
        ], $this->param('options', []));

        if (isset($options['filter'])) {

            if (is_string($options['filter'])) {

                $options['filter'] = [
                    '$or' => [
                        ['name' => ['$regex' => $options['filter']]],
                        ['user' => ['$regex' => $options['filter']]],
                        ['email' => ['$regex' => $options['filter']]],
                    ]
                ];
            }
        }

        $accounts = $this->app->storage->find('cockpit/accounts', $options)->toArray();
        $count    = (!isset($options['skip']) && !isset($options['limit'])) ? count($accounts) : $this->app->storage->count('cockpit/accounts', isset($options['filter']) ? $options['filter'] : []);
        $pages    = isset($options['limit']) ? ceil($count / $options['limit']) : 1;
        $page     = 1;

        if ($pages > 1 && isset($options['skip'])) {
            $page = ceil($options['skip'] / $options['limit']) + 1;
        }

        foreach ($accounts as &$account) {

            if (isset($account['password']))     unset($account['password']);
            if (isset($account['api_key']))      unset($account['api_key']);
            if (isset($account['_reset_token'])) unset($account['_reset_token']);
        }

        return compact('accounts', 'count', 'pages', 'page');
    }

    protected function getLanguages() {

        $languages = [['i18n' => 'en', 'language' => 'English']];

        foreach ($this->app->helper('fs')->ls('*.php', '#config:cockpit/i18n') as $file) {

            $lang     = include($file->getRealPath());
            $i18n     = $file->getBasename('.php');
            $language = $lang['@meta']['language'] ?? $i18n;

            $languages[] = ['i18n' => $i18n, 'language'=> $language];
        }

        return $languages;
    }

}
