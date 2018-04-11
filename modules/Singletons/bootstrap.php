<?php

$this->module("singletons")->extend([

    'createSingleton' => function($name, $data = []) {

        if (!trim($name)) {
            return false;
        }

        $configpath = $this->app->path('#storage:').'/singleton';

        if (!$this->app->path('#storage:singleton')) {

            if (!$this->app->helper('fs')->mkdir($configpath)) {
                return false;
            }
        }

        if ($this->exists($name)) {
            return false;
        }

        $time = time();

        $singleton = array_replace_recursive([
            'name'      => $name,
            'label'     => $name,
            '_id'       => uniqid($name),
            'fields'    => [],
            'template'  => '',
            'data'      => null,
            '_created'  => $time,
            '_modified' => $time
        ], $data);

        $this->app->trigger("singleton.save.before", [$singleton]);
        $this->app->trigger("singleton.save.before.{$name}", [$singleton]);

        $export = var_export($singleton, true);

        if (!$this->app->helper('fs')->write("#storage:singleton/{$name}.singleton.php", "<?php\n return {$export};")) {
            return false;
        }

        $this->app->trigger("singleton.save.after", [$singleton]);
        $this->app->trigger("singleton.save.after.{$name}", [$singleton]);

        return $singleton;
    },

    'updateSingleton' => function($name, $data) {

        $metapath = $this->app->path("#storage:singleton/{$name}.singleton.php");

        if (!$metapath) {
            return false;
        }

        $data['_modified'] = time();

        $singleton  = include($metapath);
        $singleton  = array_merge($singleton, $data);


        $this->app->trigger("singleton.save.before", [$singleton]);
        $this->app->trigger("singleton.save.before.{$name}", [$singleton]);

        $export  = var_export($singleton, true);

        if (!$this->app->helper('fs')->write($metapath, "<?php\n return {$export};")) {
            return false;
        }

        $this->app->trigger('singleton.save.after', [$singleton]);
        $this->app->trigger("singleton.save.after.{$name}", [$singleton]);

        return $singleton;
    },

    'saveSingleton' => function($name, $data) {

        if (!trim($name)) {
            return false;
        }

        return isset($data['_id']) ? $this->updateSingleton($name, $data) : $this->createSingleton($name, $data);
    },

    'removeSingleton' => function($name) {

        if ($singleton = $this->singleton($name)) {

            $this->app->helper("fs")->delete("#storage:singleton/{$name}.singleton.php");

            $this->app->trigger('singleton.remove', [$singleton]);
            $this->app->trigger("singleton.remove.{$name}", [$singleton]);

            return true;
        }

        return false;
    },

    'saveData' => function($name, $data) {

        if ($singleton = $this->singleton($name)) {

            $this->app->trigger('singleton.saveData.before', [$singleton, &$data]);
            $this->app->trigger("singleton.saveData.before.{$name}", [$singleton, &$data]);

            $this->app->storage->setKey('singletons', $name, $data);

            $this->app->trigger('singleton.saveData.after', [$singleton, $data]);
            $this->app->trigger("singleton.saveData.after.{$name}", [$singleton, $data]);

            return true;
        }

        return false;
    },

    'getData' => function($name, $options = []) {

        if ($singleton = $this->singleton($name)) {

            $options = array_merge([
                'user' => false,
                'populate' => false,
                'lang' => false,
                'ignoreDefaultFallback' => false
            ], $options);

            $data = $this->app->storage->getKey('singletons', $name);
            $data = $this->_filterFields($data, $singleton, $options);

            if ($options['populate'] && function_exists('cockpit_populate_collection')) {

                $fieldsFilter = [];

                if ($options['user']) $fieldsFilter['user'] = $options['user'];
                if ($options['lang']) $fieldsFilter['lang'] = $options['lang'];

                $_items = [$data];
                $_items = cockpit_populate_collection($_items, intval($options['populate']), 0, $fieldsFilter);
                $data = $_items[0];
            }

            $this->app->trigger('singleton.getData.after', [$singleton, &$data]);
            $this->app->trigger("singleton.getData.after.{$name}", [$singleton, &$data]);

            return $data;
        }

        return null;
    },

    'singletons' => function() {

        $singletons = [];

        foreach ($this->app->helper("fs")->ls('*.singleton.php', '#storage:singleton') as $path) {

            $store = include($path->getPathName());
            $singletons[$store['name']] = $store;
        }

        return $singletons;
    },

    'exists' => function($name) {
        return $this->app->path("#storage:singleton/{$name}.singleton.php");
    },

    'singleton' => function($name) {

        static $singleton; // cache

        if (is_null($singleton)) {
            $singleton = [];
        }

        if (!is_string($name)) {
            return false;
        }

        if (!isset($singleton[$name])) {

            $singleton[$name] = false;

            if ($path = $this->exists($name)) {
                $singleton[$name] = include($path);
            }
        }

        return $singleton[$name];
    },

    'getFieldValue' => function($singleton, $fieldname, $default = null, $options = []) {

        $data = $this->getData($singleton, $options);

        return ($data && isset($data[$fieldname])) ? $data[$fieldname] : $default;
    },

    '_filterFields' => function($items, $singleton, $filter) {

        static $cache;
        static $languages;

        if (null === $items) {
            return $items;
        }

        $single = false;

        if (!isset($items[0]) && count($items)) {
            $items = [$items];
            $single = true;
        }

        $filter = array_merge([
            'user' => false,
            'lang' => false,
            'ignoreDefaultFallback' => false
        ], $filter);

        extract($filter);

        if (null === $cache) {
            $cache = [];
        }

        if (null === $languages) {

            $languages = [];

            foreach($this->app->retrieve('config/languages', []) as $key => $val) {
                if (is_numeric($key)) $key = $val;
                $languages[] = $key;
            }
        }

        if (is_string($singleton)) {
            $singleton = $this->collection($singleton);
        }

        if (!isset($cache[$singleton['name']])) {

            $fields = [
                "acl" => [],
                "localize" => []
            ];

            foreach ($singleton["fields"] as $field) {

                if (isset($field['acl']) && is_array($field['acl']) && count($field['acl'])) {
                    $fields['acl'][$field['name']] = $field['acl'];
                }

                if (isset($field['localize']) && $field['localize']) {
                    $fields['localize'][$field['name']] = true;
                }
            }

            $cache[$singleton['name']] = $fields;
        }

        if ($user && count($cache[$singleton['name']]['acl'])) {

            $aclfields = $cache[$singleton['name']]['acl'];
            $items     = array_map(function($entry) use($user, $aclfields, $languages) {

                foreach ($aclfields as $name => $acl) {

                    if (!( in_array($user['group'], $acl) || in_array($user['_id'], $acl) )) {

                        unset($entry[$name]);

                        if (count($languages)) {

                            foreach($languages as $l) {
                                if (isset($entry["{$name}_{$l}"])) {
                                    unset($entry["{$name}_{$l}"]);
                                    unset($entry["{$name}_{$l}_slug"]);
                                }
                            }
                        }
                    }
                }

                return $entry;

            }, $items);
        }

        if ($lang && count($languages) && count($cache[$singleton['name']]['localize'])) {

            $localfields = $cache[$singleton['name']]['localize'];
            $items = array_map(function($entry) use($localfields, $lang, $languages, $ignoreDefaultFallback) {

                foreach ($localfields as $name => $local) {

                    foreach($languages as $l) {

                        if (isset($entry["{$name}_{$l}"])) {

                            if ($l == $lang) {

                                $entry[$name] = $entry["{$name}_{$l}"];

                                if (isset($entry["{$name}_{$l}_slug"])) {
                                    $entry["{$name}_slug"] = $entry["{$name}_{$l}_slug"];
                                }
                            }

                            unset($entry["{$name}_{$l}"]);
                            unset($entry["{$name}_{$l}_slug"]);

                        } elseif ($l == $lang && $ignoreDefaultFallback) {

                            if ($ignoreDefaultFallback === true || (is_array($ignoreDefaultFallback) && in_array($name, $ignoreDefaultFallback))) {
                                $entry[$name] = null;
                            }
                        }
                    }
                }

                return $entry;

            }, $items);
        }

        return $single ? $items[0] : $items;
    }

]);

// ACL
$app("acl")->addResource("singletons", ['create', 'delete']);

$this->module('singletons')->extend([

    'getSingletonsInGroup' => function($group = null) {

        if (!$group) {
            $group = $this->app->module('cockpit')->getGroup();
        }

        $_singletons = $this->singletons();
        $singletons = [];

        if ($this->app->module('cockpit')->isSuperAdmin()) {
            return $_singletons;
        }

        foreach ($_singletons as $singleton => $meta) {

            if (isset($meta['acl'][$group]['form']) && $meta['acl'][$group]['form']) {
                $singletons[$singleton] = $meta;
            }
        }

        return $singletons;
    },

    'hasaccess' => function($singleton, $action, $group = null) {

        $singleton = $this->singleton($singleton);

        if (!$singleton) {
            return false;
        }

        if (!$group) {
            $group = $this->app->module('cockpit')->getGroup();
        }

        if ($this->app->module('cockpit')->isSuperAdmin($group)) {
            return true;
        }

        if (isset($singleton['acl'][$group][$action])) {
            return $singleton['acl'][$group][$action];
        }

        return false;
    }
]);

// REST
if (COCKPIT_API_REQUEST) {

    $app->on('cockpit.rest.init', function($routes) {
        $routes['singletons'] = 'Singletons\\Controller\\RestApi';
    });

    // allow access to public collections
    $app->on('cockpit.api.authenticate', function($data) {

        if ($data['user'] || $data['resource'] != 'singletons') return;

        if (isset($data['query']['params'][1])) {

            $singleton = $this->module('singletons')->singleton($data['query']['params'][1]);

            if ($singleton && isset($singleton['acl']['public'])) {
                $data['authenticated'] = true;
                $data['user'] = ['_id' => null, 'group' => 'public'];
            }
        }
    });
}

// ADMIN
if (COCKPIT_ADMIN && !COCKPIT_API_REQUEST) {
    include_once(__DIR__.'/admin.php');
}
