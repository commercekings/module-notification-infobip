<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Block\Adminhtml\System\Config;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\NotificationInfobip\Model\Webhook\WebhookAuth;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The URLs to enter in Infobip for the messages to the shop ("Forward to HTTP", one per channel), with their tokens -
 * for the website being edited (the default store view at default scope). The delivery report URL needs no entry:
 * every message carries it.
 */
class WebhookUrls extends Field
{
    public function __construct(
        Context $context,
        private readonly WebhookAuth $auth,
        private readonly StoreManagerInterface $stores,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $storeId = $this->storeId();
        $rows = [];
        // no <dl>: the admin's accordion CSS hides every <dd> inside a config group
        foreach ([Channel::SMS => 'SMS', Channel::WHATSAPP => 'WhatsApp', Channel::VIBER => 'Viber'] as $channel => $label) {
            $url = $this->auth->url($storeId, WebhookAuth::INBOUND, $channel);
            if ($url === '') {
                return '<p>' . $this->escapeHtml((string)__('Set a webhook secret and save first.')) . '</p>';
            }
            $id = $this->escapeHtml($element->getHtmlId() . '_' . $channel);
            $rows[] = '<div class="ck-infobip-webhook-url" style="margin-bottom:8px"><label for="' . $id . '">'
                . $this->escapeHtml($label) . '</label><input type="text" readonly="readonly" class="input-text admin__control-text"'
                . ' id="' . $id . '" value="' . $this->escapeHtml($url) . '"/></div>';
        }
        return implode('', $rows);
    }

    private function storeId(): int
    {
        $request = $this->getRequest();
        if ($request->getParam('store')) {
            return (int)$request->getParam('store');
        }
        $website = $request->getParam('website');
        $website = $website ? $this->stores->getWebsite((int)$website) : $this->stores->getDefaultStoreView()?->getWebsite();
        return $website ? (int)$this->stores->getGroup((string)$website->getDefaultGroupId())->getDefaultStoreId() : 0;
    }
}
