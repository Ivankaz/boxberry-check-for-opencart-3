<?php
require_once(__DIR__ . '/boxberryCheckLoader.php');

/*
 * Синхронизация статуса заказа в магазине со статусом заказа в Boxberry
 */

// токен API Boxberry
$registry->set('token', 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
/*
 * ID статусов заказа в ИМ
 * 15 - Отправлен
 * 27 - Поступил в пункт выдачи
 * 10 - Получено
 * 8 - Возврат
 */
$registry->set('statusIdToStatusName', [
  '15' => 'Отправлено',
  '27' => 'Поступил в пункт выдачи',
  '10' => 'Получено',
  '8' => 'Возврат',
]);

$registry->set('statusToStatus', [
  "Заказ создан в личном кабинете" => "15",
  "Загружен реестр ИМ" => "15",
  "Заказ передан на доставку" => "15",
  "Принято к доставке" => "15",
  "Отправлен на сортировочный терминал" => "15",
  "Принято к доставке" => "15",
  "Передано на сортировку" => "15",
  "Отправлено в город назначения" => "15",
  "Передан на доставку до пункта выдачи" => "15",
  "Передан на доставку до Пункта выдачи" => "15",
  "В городе Получателя" => "15",
  "Передано на курьерскую доставку" => "15",
  "Поступило в пункт выдачи" => "27",
  "Выдано" => "10",
  "Возвращено с курьерской доставки" => "8",
  "Готовится к возврату" => "8",
  "Отправлено в пункт приема" => "8",
  "Возвращено в пункт приема" => "8",
  "Возвращено в ИМ" => "8",
]);

class ControllerCheckBoxberry extends Controller
{
  protected $log = '';

  public function log($text = '') {
    $this->log .= $text;
  }

  public function saveLog() {
    // определяем директорию скрипта
    $path_parts = pathinfo($_SERVER['SCRIPT_FILENAME']);
    // задаем директорию выполнение скрипта
    chdir($path_parts['dirname']);

    // Пишем содержимое в файл,
    // используя флаг FILE_APPEND для дописывания содержимого в конец файла
    // и флаг LOCK_EX для предотвращения записи данного файла кем-нибудь другим в данное время
    file_put_contents('boxberryCheckLog.html', $this->log, LOCK_EX);
  }

  // получить ID статуса заказа
  public function getOrderStatusId($orderId = null) {
    if ($orderId === null) return false;

    $query = $this->db->query("SELECT `order_status_id` FROM `" . DB_PREFIX . "order` WHERE `order_id` = '" . (int)$orderId . "' AND `customer_id` != '0' AND `order_status_id` > '0'");

    if ($query->num_rows) {
      return (int)$query->row['order_status_id'];
    } else {
      // если не нашли заказ
      return false;
    }
  }

  // изменить ID статуса заказа
  public function setOrderStatusId($orderId = null, $statusId = null) {
    if (($orderId === null) || ($statusId === null)) return false;

    $query = $this->db->query("UPDATE `" . DB_PREFIX . "order` SET `order_status_id` = '" . (string)$statusId . "' WHERE `order_id` = '" . (int)$orderId . "'");
    $this->log('Обновлен статус заказа</br>');

    return $query;
  }

  // обновить статус заказа в магазине
  public function checkOrderStatus($order = null) {
    $this->log('<b>Заказ ' . $order['order_id'] . '</b><br/>');

    $urlListStatuses = 'https://api.boxberry.ru/json.php?method=ListStatuses&token=' . $this->token . '&ImId=' . $order['order_id'];
    $requestListStatuses = file_get_contents($urlListStatuses);
    $listStatuses = json_decode($requestListStatuses, true);
    $lastStatus = end($listStatuses);

    // если нет поля с названием статуса
    if (!isset($lastStatus['Name'])) {
      $this->log('Нет поля Name для статуса в ответе от API Боксберри<br/>');
      return false;
    }

    // название статуса в Боксберри
    $nameLastStatus = $lastStatus['Name'];
    // новый ID статуса заказа, которому соответствует статус Боксберри
    $newStatusId = $this->statusToStatus[$nameLastStatus] ?: null;
    if ($newStatusId === null) {
      return false;
    }

    // название нового статуса заказа
    $newStatusName = $this->statusIdToStatusName[(string)$newStatusId];

    // если статусы не совпадают
    if ($newStatusId !== $order['order_status_id']) {
      $this->log('Статусы не совпадают<br/>');
      $this->load->model('sale/order');

      // если заказ впервые получает статус "Отправлен"
      if (($newStatusId == "15") && ($order['track_no'] != null)) {
        $this->log('Статус "Отправлено" получен впервые<br/>');
        // ссылка для отслеживания заказа
        $trackingLink = 'https://boxberry.ru/tracking-page?id=' . $order['track_no'];

        // добавляем информацию в историю заказа
        $data = array(
          'order_status_id' => $newStatusId,
          'notify' => 1,
          'comment' => 'Ваш заказ отправлен.<br/>Треккинг-номер: ' . $order['track_no'] . '<br/>Вы можете самостоятельно отследить свою посылку, перейдя по ссылке <a href="' . $trackingLink . '">' . $trackingLink . '</a>'
        );

        $this->model_sale_order->addOrderHistory($order['order_id'], $data);
        $this->log('Установлен статус "' . $newStatusName . '"</br>');

        // если есть Email клиента
        if ($order['email'] != null) {
          $this->log('Email клиента: '. $order['email'] . '<br/>');

          // заголовки письма
          $headers  = "MIME-Version: 1.0\r\n";
          $headers .= "Content-type: text/html; charset=utf-8\r\n";
          $headers .= "From: Интернет-магазин Kolbaskidoma <info@kolbaskidoma.ru>"."\r\n";
          $headers .= "Reply-To: Интернет-магазин Kolbaskidoma <info@kolbaskidoma.ru>\r\n";

          // заголовок письма
          $title = 'Интернет-магазин Kolbaskidoma (Ростов-на-Дону) - заказ №' . $order['order_id'] . ' отправлен';
          // текст письма
          $text = 'Ваш заказ отправлен.<br/>Треккинг-номер: ' . $order['track_no'] . '<br/>Вы можете самостоятельно отследить свою посылку, перейдя по ссылке <a href="' . $trackingLink . '">' . $trackingLink . '</a>';

          // отправляем письмо клиенту на почту
          mail($order['email'], $title, $text, $headers);
          $this->log('Отправлено письмо клиенту<br/>');
        }

        // если есть телефон клиента
        if ($order['telephone'] != null) {
          $this->log('Телефон клиента: '. $order['telephone'] . '<br/>');

          $this->load->library('cart/currency');

          $params = array(
            "text" => 'Заказ №' . $order['order_id'] . ' отправлен. ТРЕК ' . $order['track_no'] . ',сумма-' . $this->currency->convert($order['total'], $order['currency_code'], 'RUB') . 'руб'
          );

          // API
          $api = new Transport();

          // отправляем СМС клиенту
          $send = $api->send($params, $order['telephone']);
          $this->log('Отправлено СМС клиенту<br/>');
        }
      } else {
         // добавляем информацию в историю заказа
        $data = array(
          'order_status_id' => $newStatusId,
          'notify' => 0,
          'comment' => ''
        );

        $this->model_sale_order->addOrderHistory($order['order_id'], $data);
        $this->log('Установлен статус "' . $newStatusName . '"</br>');
      }

      // обновляем статус заказа
      return $this->setOrderStatusId($order['order_id'], $newStatusId);
    } else {
      $this->log('Статус у Боксберри и в магазине не изменялся<br/>');
    }
  }

  // получить последние заказы Боксберри из БД магазина
  public function getLastOrders() {
    /* Статусы заказов, которые не обрабатываем
     * 5 - Сделка завершена
     * 8 - Возврат
     * 13 - Полный возврат
     * 26 - Возврат не по вине клиента
     */
    $query = $this->db->query("SELECT `order_id`, `order_status_id`, `track_no`, `email`, `telephone`, `total`, `currency_code` FROM `" . DB_PREFIX ."order` WHERE (`shipping_code`) IN ('boxberry.pickup', 'boxberry.courier_delivery', 'bb.pickup', 'bb.kd') AND `date_added` >= '2021-11-20 00:00:00' AND `order_status_id` NOT IN (5, 8, 13, 26) ORDER BY `order_id` DESC LIMIT 50");
    return $query->rows;
  }

  // обновить статус у последних заказов Боксберри
  public function updateStatusForLastOrders() {
    // устанавливаем часовую зону Ростова-на-Дону
    date_default_timezone_set('Europe/Moscow');

    $this->log('Запущено обновление статусов заказов с доставкой Боксберри (' . date('d.m.Y H:i:s') . ')</br>');

    // последние заказы
    $lastOrders = $this->getLastOrders();

    // если нет подходящих заказов
    if (count($lastOrders)==0) exit("Среди последних заказов нет доставки Боксберри");

    // для каждого последнего заказа с доставкой Боксберри
    foreach ($lastOrders as $lastOrder) {
      // проверяем статус заказа и обновляем его, если он изменился
      $this->checkOrderStatus($lastOrder);
    }

    $this->saveLog();

    return true;
  }
}


$checkBoxberry = new ControllerCheckBoxberry($registry); // $registry = new Registry();
// обновляем статусы последних заказов с доставкой Боксберри
$checkBoxberry->updateStatusForLastOrders();
