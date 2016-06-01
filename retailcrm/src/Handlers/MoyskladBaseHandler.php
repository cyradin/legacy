<?php

class MoyskladBaseHandler implements HandlerInterface {

    public $moysklad;
    public $container;

    public function __construct() {
        $this->container = Container::getInstance();

        $this->moysklad = new MsRestApi(
            $this->container->moysklad['login'],
            $this->container->moysklad['password']
        );

    }

    protected $itemCache = array();

    public function prepare($lastSync) {
        $orders = array();

        $start = 0;
        $max = $this->container->moysklad['count'];

        $filter = $this->getFilter($lastSync);

        $count = 0;
        do {
            $msOrders = $this->getOrders($filter, $start, $max);

            $attr = $msOrders->attributes();
            $total = (int)$attr['total'];
            $count += (int)$attr['count'];

            foreach ($msOrders->customerOrder as $customerOrder) {
                $orders[] = $this->processOrder($customerOrder);
            }
die();
            $start += $max;
        } while ($count < $total);

        return $orders;
    }

    public function getFilter($lastSync = null) {
        if (isset($this->container->settings['moysklad']['filter'])) {
            $filter = $this->container->settings['moysklad']['filter'];
        } else {
            $filter = array();
        }

        if ($lastSync) {
            $date = new DateTime($lastSync);
            $filter['created>'] = $date->format('YmdHis');
        }

        return $filter;
    }

    public function getOrders($filter, $start, $count) {
        return $this->moysklad->customerOrderGetList($filter, $start, $count);
    }

    public function processOrder(SimpleXMLElement $msOrder) {
        $attr = $msOrder->attributes();


        $created = new DateTime($attr['created']);

        $order = array(
            'createdAt' => $created->format('Y-m-d H:i:s'),
            'managerComment' => (string)$msOrder->description,
            'delivery' => array()
        );
        if ((string)$attr['deliveryPlannedMoment']) {
            $deliveryDate = new DateTime((string)$attr['deliveryPlannedMoment']);
            $order['delivery']['date'] = $deliveryDate->format('Y-m-d');
            $order['delivery']['address']['deliveryTime'] = 'В ' . $deliveryDate->format('H:i:s');
        }

        $msCustomer = $this->moysklad->companyGet((string)$attr['sourceAgentUuid']);
        $contact = $msCustomer->contact->attributes();

        $customerAttr = $msCustomer->attributes();
        $order = array_merge($order, DataHelper::explodeFIO((string)$customerAttr['name']));

        if ((string)$contact['email']) {
            $order['email'] = (string)$contact['email'];
        }

        if ((string)$contact['phones']) {
            $order['phone'] = (string)$contact['phones'];
        }

        if ((string)$contact['mobiles']) {
            $order['additionalPhone'] = (string)$contact['mobiles'];
        }

        if ($msCustomer->externalcode) {
            $order['customerId'] = (string)$msCustomer->externalcode;
        } elseif ($this->container->settings['moysklad']['external_id_format']) {
            $order['customerId'] = sprintf($this->container->settings['moysklad']['external_id_format'], uniqid());
        } else {
            $order['customerId'] = uniqid();
        }

        if ((string)$msOrder->externalcode != '') {
            $order['externalId'] = (string)$msOrder->externalcode;
        } elseif ($this->container->settings['moysklad']['external_id_format']) {
            $order['externalId'] = sprintf($this->container->settings['moysklad']['external_id_format'], uniqid());
        }

        $order['number'] = (string)$attr['name'];
        $order['items'] = $this->processProducts($msOrder->customerOrderPosition);

        return $order;
    }

    public function processProducts(SimpleXMLElement $products) {
        $items = array();
        foreach ($products as $product) {
            $attr = $product->attributes();
            $xmlId = $this->getXmlId((string)$attr['goodUuid']);

            $baseprice = $product->basePrice;
            $basepriceAttr = $baseprice->attributes();
            $initialPrice = (float)$basepriceAttr['sum'];

            $item = array(
                'discount' => (float)$attr['discount'],
                'quantity' => (float)$attr['quantity'],
                'xmlId' => $xmlId,
                'initialPrice' => $initialPrice
            );

            $items[] = $item;
        }

        return $items;
    }

    public function getXmlId($goodUuid) {
        if (! isset($this->itemCache[$goodUuid])) {
            try {
                $good = $this->moysklad->goodGet($goodUuid);
            } catch (MSException $e) {
                return '';
            }
            $this->itemCache[$goodUuid] = (string)$good->externalcode;
        }

        return $this->itemCache[$goodUuid];
    }
}