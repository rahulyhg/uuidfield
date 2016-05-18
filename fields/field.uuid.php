<?php

require_once __DIR__ . '/../vendor/autoload.php';

require_once FACE . '/interface.exportablefield.php';
require_once FACE . '/interface.importablefield.php';

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\Exception\UnsatisfiedDependencyException;

class FieldUUID extends Field implements ExportableField, ImportableField
{
    public function __construct()
    {
        parent::__construct();
        $this->_name = __('UUID');
        $this->_required = true;

        $this->set('required', 'no');
        $this->set('auto_generate', 'no');
    }

    /*-------------------------------------------------------------------------
        Definition:
    -------------------------------------------------------------------------*/

    public function canFilter()
    {
        return true;
    }

    public function canPrePopulate()
    {
        return true;
    }

    public function isSortable()
    {
        return true;
    }

    public function allowDatasourceParamOutput()
    {
        return true;
    }

    /*-------------------------------------------------------------------------
        Setup:
    -------------------------------------------------------------------------*/

    public function createTable()
    {
        return Symphony::Database()->query(
            "CREATE TABLE IF NOT EXISTS `tbl_entries_data_" . $this->get('id') . "` (
              `id` int(11) unsigned NOT null auto_increment,
              `entry_id` int(11) unsigned NOT null,
              `value` varchar(36) default null,
              PRIMARY KEY  (`id`),
              UNIQUE KEY `entry_id` (`entry_id`),
              KEY `value` (`value`)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;"
        );
    }

    /*-------------------------------------------------------------------------
        Utilities:
    -------------------------------------------------------------------------*/

    private function __applyValidationRules($data)
    {
        return General::validateString($data, "@^[A-G0-9]{8}-[A-G0-9]{4}-[A-G0-9]{4}-[A-G0-9]{4}-[A-G0-9]{12}$@i");
    }


    /*-------------------------------------------------------------------------
        Settings:
    -------------------------------------------------------------------------*/

    public function findDefaults(array &$settings){
        if(!isset($settings['auto_generate'])) $settings['auto_generate'] = 'yes';
    }

    public function displaySettingsPanel(XMLElement &$wrapper, $errors = null)
    {
        parent::displaySettingsPanel($wrapper, $errors);

        // Options
        $div = new XMLElement('div', NULL, array('class' => 'two columns'));
        $wrapper->appendChild($div);

        // Hide when prepopulated
        $label = Widget::Label();
        $label->setAttribute('class', 'column');
        $input = Widget::Input('fields['.$this->get('sortorder').'][auto_generate]', 'yes', 'checkbox');

        if($this->get('auto_generate') == 'yes') {
            $input->setAttribute('checked', 'checked');
        }

        $label->setValue($input->generate() . ' ' . __('Auto generate UUID when blank'));
        $div->appendChild($label);

        // Requirements and table display
        $this->appendStatusFooter($wrapper);
    }

    public function commit()
    {
        if (!parent::commit()) {
            return false;
        }

        $id = $this->get('id');
        if($id === false) return false;

        $fields = [];
        $fields['auto_generate'] = $this->get('auto_generate') == 'yes' ? 'yes' : 'no';

        return FieldManager::saveSettings($id, $fields);
    }

    /*-------------------------------------------------------------------------
        Publish:
    -------------------------------------------------------------------------*/

    public function displayPublishPanel(XMLElement &$wrapper, $data = null, $flagWithError = null, $fieldnamePrefix = null, $fieldnamePostfix = null, $entry_id = null)
    {
        $value = General::sanitize(isset($data['value']) ? $data['value'] : null);
        $label = Widget::Label($this->get('label'));

        if ($this->get('required') !== 'yes') {
            $label->appendChild(new XMLElement('i', __('Optional')));
        }

        $label->appendChild(Widget::Input('fields'.$fieldnamePrefix.'['.$this->get('element_name').']'.$fieldnamePostfix, (strlen($value) != 0 ? $value : null)));

        if ($flagWithError != null) {
            $wrapper->appendChild(Widget::Error($label, $flagWithError));
        } else {
            $wrapper->appendChild($label);
        }
    }

    public function checkPostFieldData($data, &$message, $entry_id = null)
    {
        $message = null;

        if (is_array($data) && isset($data['value'])) {
            $data = $data['value'];
        }

        if ($this->get('required') == 'yes' && $this->get('auto_generate') != 'yes' && strlen(trim($data)) == 0) {
            $message = __('‘%s’ is a required field.', array($this->get('label')));
            return self::__MISSING_FIELDS__;
        }

        if (!$this->__applyValidationRules($data)) {
            $message = __('‘%s’ is not a valid UUID. Please check the contents.', array($this->get('label')));
            return self::__INVALID_FIELDS__;
        }

        return self::__OK__;
    }

    public function processRawFieldData($data, &$status, &$message = null, $simulate = false, $entry_id = null)
    {
        $status = self::__OK__;

        if($this->get('auto_generate') == 'yes' && strlen(trim($data)) == 0) {
            $data = Uuid::uuid1()->toString();

        } elseif (strlen(trim($data)) == 0) {
            return [];
        }

        $result = [
            'value' => $data
        ];

        return $result;
    }

    /*-------------------------------------------------------------------------
        Output:
    -------------------------------------------------------------------------*/

    public function appendFormattedElement(XMLElement &$wrapper, $data, $encode = false, $mode = null, $entry_id = null)
    {
        $value = $data['value'];

        if ($encode === true) {
            $value = General::sanitize($value);
        } else {
            include_once TOOLKIT . '/class.xsltprocess.php';

            if (!General::validateXML($data['value'], $errors, false, new XsltProcess)) {
                $value = html_entity_decode($data['value'], ENT_QUOTES, 'UTF-8');
                $value = $this->__replaceAmpersands($value);

                if (!General::validateXML($value, $errors, false, new XsltProcess)) {
                    $value = General::sanitize($data['value']);
                }
            }
        }

        $wrapper->appendChild(
            new XMLElement($this->get('element_name'), $value)
        );
    }

    /*-------------------------------------------------------------------------
        Import:
    -------------------------------------------------------------------------*/

    public function getImportModes()
    {
        return array(
            'getValue' =>       ImportableField::STRING_VALUE,
            'getPostdata' =>    ImportableField::ARRAY_VALUE
        );
    }

    public function prepareImportValue($data, $mode, $entry_id = null)
    {
        $message = $status = null;
        $modes = (object)$this->getImportModes();

        if ($mode === $modes->getValue) {
            return $data;
        } elseif ($mode === $modes->getPostdata) {
            return $this->processRawFieldData($data, $status, $message, true, $entry_id);
        }

        return null;
    }

    /*-------------------------------------------------------------------------
        Export:
    -------------------------------------------------------------------------*/

    /**
     * Return a list of supported export modes for use with `prepareExportValue`.
     *
     * @return array
     */
    public function getExportModes()
    {
        return array(
            'getUnformatted' => ExportableField::UNFORMATTED,
            'getPostdata' =>    ExportableField::POSTDATA
        );
    }

    /**
     * Give the field some data and ask it to return a value using one of many
     * possible modes.
     *
     * @param mixed $data
     * @param integer $mode
     * @param integer $entry_id
     * @return string|null
     */
    public function prepareExportValue($data, $mode, $entry_id = null)
    {
        $modes = (object)$this->getExportModes();

        // Export unformatted:
        if ($mode === $modes->getUnformatted || $mode === $modes->getPostdata) {
            return isset($data['value'])
                ? $data['value']
                : null;
        }

        return null;
    }

    /*-------------------------------------------------------------------------
        Filtering:
    -------------------------------------------------------------------------*/

    public function buildDSRetrievalSQL($data, &$joins, &$where, $andOperation = false)
    {
        $field_id = $this->get('id');

        if (self::isFilterRegex($data[0])) {
            $this->buildRegexSQL($data[0], array('value'), $joins, $where);
        } else if ($andOperation) {
            foreach ($data as $value) {
                $this->_key++;
                $value = $this->cleanValue($value);
                $joins .= "
                    LEFT JOIN
                        `tbl_entries_data_{$field_id}` AS t{$field_id}_{$this->_key}
                        ON (e.id = t{$field_id}_{$this->_key}.entry_id)
                ";
                $where .= "
                    AND (
                        t{$field_id}_{$this->_key}.value = '{$value}'
                    )
                ";
            }
        } else {
            if (!is_array($data)) {
                $data = array($data);
            }

            foreach ($data as &$value) {
                $value = $this->cleanValue($value);
            }

            $this->_key++;
            $data = implode("', '", $data);
            $joins .= "
                LEFT JOIN
                    `tbl_entries_data_{$field_id}` AS t{$field_id}_{$this->_key}
                    ON (e.id = t{$field_id}_{$this->_key}.entry_id)
            ";
            $where .= "
                AND (
                    t{$field_id}_{$this->_key}.value IN ('{$data}')
                )
            ";
        }

        return true;
    }

    /*-------------------------------------------------------------------------
        Sorting:
    -------------------------------------------------------------------------*/

    public function buildSortingSQL(&$joins, &$where, &$sort, $order = 'ASC')
    {
        if (in_array(strtolower($order), array('random', 'rand'))) {
            $sort = 'ORDER BY RAND()';
        } else {
            $sort = sprintf(
                'ORDER BY (
                    SELECT %s
                    FROM tbl_entries_data_%d AS `ed`
                    WHERE entry_id = e.id
                ) %s',
                '`ed`.value',
                $this->get('id'),
                $order
            );
        }
    }

}