<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Block\Adminhtml\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;

/** WhatsApp templates: one row per notification type and locale, with the template's name, language and variables. */
class TemplateMap extends AbstractFieldArray
{
    protected function _prepareToRender(): void
    {
        $this->addColumn('type', ['label' => __('Notification type'), 'class' => 'required-entry']);
        $this->addColumn('locale', ['label' => __('Locale')]);
        $this->addColumn('template', ['label' => __('Template name'), 'class' => 'required-entry']);
        $this->addColumn('language', ['label' => __('Template language')]);
        $this->addColumn('variables', ['label' => __('Variables, in order')]);
        $this->_addAfter = false;
        $this->_addButtonLabel = (string)__('Add template');
    }
}
