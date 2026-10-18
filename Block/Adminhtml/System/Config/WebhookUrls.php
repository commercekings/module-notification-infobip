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
        foreach ([Channel::SMS => 'SMS', Channel::WHATSAPP => 'WhatsApp', Channel::VIBER => 'Viber'] as $channel => $label) {
            $url = $this->auth->url($storeId, WebhookAuth::INBOUND, $channel);
            $rows[] = '<dt>' . $this->escapeHtml($label) . '</dt><dd><code>'
                . $this->escapeHtml($url !== '' ? $url : (string)__('Set a webhook secret and save first.')) . '</code></dd>';
        }
        return '<dl>' . implode('', $rows) . '</dl>';
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
