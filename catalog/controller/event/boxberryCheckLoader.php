<?php
require_once(__DIR__ . '/../../../admin/config.php');
require_once(DIR_SYSTEM . 'startup.php');
require_once(__DIR__ . '/../../../admin/transport.php');

// Registry
$registry = new Registry();

// Config
$config = new Config();
$config->load('catalog');
$registry->set('config', $config);

// Log
$log = new Log('custom_log');
$registry->set('log', $log);

date_default_timezone_set('UTC');

// Event
$event = new Event($registry);
$registry->set('event', $event);

// Event Register
if ($config->has('action_event')) {
  foreach ($config->get('action_event') as $key => $value) {
    foreach ($value as $priority => $action) {
      $event->register($key, new Action($action), $priority);
    }
  }
}

// Loader
$loader = new Loader($registry);
$registry->set('load', $loader);

// Database
$registry->set('db', new DB(
  $config->get('db_engine'),
  $config->get('db_hostname'),
  $config->get('db_username'),
  $config->get('db_password'),
  $config->get('db_database'),
  $config->get('db_port'))
);

// данные сеты необходимы для использования стандартных методов моделей
$config->set('config_customer_group_id', 1);
$config->set('config_language_id', 1);
$config->set('config_store_id', 0);

