<?php

declare(strict_types=1);
/**
 * Creates a Multiline field within a table, which allows adding/editing multiple
 * data rows.
 *
 * To save the data, use the Multiline::saveRows() method. If the Multiline's
 * model is a reference to your form's model, the form model should be saved prior
 * to calling saveRows().
 *
 * $form = \atk4\ui\Form::addTo($app);
 * $form->setModel($invoice, false);
 * // Add form controls
 *
 * // Add Multiline form control and set model for it.
 * $ml = $form->addControl('ml', ['Multiline']);
 *
 * // Set model using hasMany reference of Invoice.
 * $ml->setModel($invoice, ['item','cat','qty','price', 'total'], 'Items', 'invoice_id');
 *
 * $form->onSubmit(function($form) use ($ml) {
 *     // Save Form model and then Multiline model
 *     $form->model->save();
 *     $ml->saveRows();
 *     return new \atk4\ui\jsToast('Saved!');
 * });
 *
 * If Multiline's model contains expressions, these will be evaluated on the fly
 * whenever data gets entered.
 *
 * Multiline input also has an onChange callback that will return all data rows
 * in an array. It is also possible to fire onChange handler only for certain
 * fields by passing them as an array to the method.
 *
 * Note that deleting a row will always fire the onChange callback.
 *
 * You can use the returned data to update other related areas of the form.
 * For example, ypdating Grand Total field of all invoice items.
 *
 * $ml->onChange(function($rows) use ($form) {
 *     $grand_total = 0;
 *     foreach ($rows as $row => $cols) {
 *         foreach ($cols as $col) {
 *             $fieldName = key($col);
 *                 if ($fieldName === 'total') {
 *                     $grand_total = $grand_total + $col[$fieldName];
 *                 }
 *          }
 *     }
 *
 *   return $form->js(true, null, 'input[name="grand_total"]')->val(number_format($grand_total, 2));
 * }, ['qty', 'price']);
 *
 * Finally, it's also possible to use Multiline for quickly adding records to a
 * model. Be aware that in the example below all User records will be displayed.
 * If your model contains a lot of records, you should handle their limit somehow.
 *
 * $form = \atk4\ui\Form::addTo($app);
 * $ml = $form->addControl('ml', [\atk4\ui\Form\Control\Multiline::class]);
 * $ml->setModel($user, ['name','is_vip']);
 *
 * $form->onSubmit(function($form) use ($ml) {
 *     $ml->saveRows();
 *     return new \atk4\ui\jsToast('Saved!');
 * });
 */

namespace atk4\ui\Form\Control;

use atk4\data\Field\Callback;
use atk4\data\Field_SQL_Expression;
use atk4\data\Model;
use atk4\data\Reference\HasOne;
use atk4\data\ValidationException;
use atk4\ui\Exception;
use atk4\ui\Form;
use atk4\ui\jsVueService;
use atk4\ui\Template;

class Multiline extends Form\Control
{
    /**
     * The template needed for the multiline view.
     *
     * @var Template
     */
    public $multiLineTemplate;

    /**
     * The multiline View. Assigned in init().
     *
     * @var \atk4\ui\View
     */
    private $multiLine;

    /**
     * An array of options for sui-table property.
     * Example: ['celled' => true] will render column lines in table.
     *
     * @var array
     */
    public $options = [];

    /**
     * When true, tabbing out of the last column in last row of data
     * will automatically add a new row of record.
     *
     * @var bool
     */
    public $addOnTab = false;

    /**
     * The definition of each field used in every multiline row.
     *
     * @var array
     */
    private $fieldDefs;

    /**
     * The JS callback.
     *
     * @var \atk4\ui\jsCallback
     */
    private $cb;

    /**
     * The callback function which gets triggered when fields are changed or
     * rows get deleted.
     *
     * @var callable
     */
    public $changeCb;

    /**
     * Array of field names that will trigger the change callback when those
     * fields are changed.
     *
     * @var array
     */
    public $eventFields;

    /**
     * Collection of field errors.
     *
     * @var array
     */
    private $rowErrors;

    /**
     * The model reference name used for Multiline input.
     *
     * @var string
     */
    public $modelRef;

    /**
     * The link field used for reference.
     *
     * @var string
     */
    public $linkField;

    /**
     * The fields names used in each row.
     *
     * @var array
     */
    public $rowFields;

    /**
     * The data sent for each row.
     *
     * @var array
     */
    public $rowData;

    /**
     * The max number of records that can be added to Multiline. 0 means no limit.
     *
     * @var int
     */
    public $rowLimit = 0;

    /**
     * Model's max rows limit to be used in enum fields.
     * Enum fields are display as Dropdown inputs.
     * This limit is set on model reference used by a field.
     *
     * @var int
     */
    public $enumLimit = 100;

    /**
     * Multiline's caption.
     *
     * @var string
     */
    public $caption;

    /**
     * @var jsFunction|null
     *
     * A jsFunction to execute when Multiline add(+) button is clicked.
     * The function is execute after mulitline component finish adding a row of fields.
     * The function also receive the row vaue as an array.
     * ex: $jsAfterAdd = new jsFunction(['value'],[new jsExpression('console.log(value)')]);
     */
    public $jsAfterAdd;

    /**
     * @var jsFunction|null
     *
     * A jsFunction to execute when Multiline delete button is clicked.
     * The function is execute after mulitline component finish deleting rows.
     * The function also receive the row vaue as an array.
     * ex: $jsAfterDelete = new jsFunction(['value'],[new jsExpression('console.log(value)')]);
     */
    public $jsAfterDelete;

    public function init(): void
    {
        parent::init();

        if (!$this->multiLineTemplate) {
            $this->multiLineTemplate = new Template('<div id="{$_id}" class="ui"><atk-multiline v-bind="initData"></atk-multiline><div class="ui hidden divider"></div>{$Input}</div>');
        }

        /* No need for this anymore. See: https://github.com/atk4/ui/commit/8ec4d22cf9dcbd4969d9c88d8f09b705ca8798a6
        if ($this->model) {
            $this->setModel($this->model);
        }
        */

        $this->multiLine = \atk4\ui\View::addTo($this, ['template' => $this->multiLineTemplate]);

        $this->cb = \atk4\ui\jsCallback::addTo($this);

        // load the data associated with this input and validate it.
        $this->form->onHook(\atk4\ui\Form::HOOK_LOAD_POST, function ($form) {
            $this->rowData = json_decode($_POST[$this->short_name], true);
            if ($this->rowData) {
                $this->rowErrors = $this->validate($this->rowData);
                if ($this->rowErrors) {
                    throw new ValidationException([$this->short_name => 'multiline error']);
                }
            }
        });

        // Change form error handling.
        $this->form->onHook(\atk4\ui\Form::HOOK_DISPLAY_ERROR, function ($form, $fieldName, $str) {
            // When errors are coming from this Multiline field, then notify Multiline component about them.
            // Otherwise use normal field error.
            if ($fieldName === $this->short_name) {
                $jsError = [(new jsVueService())->emitEvent('atkml-row-error', ['id' => $this->multiLine->name, 'errors' => $this->rowErrors])];
            } else {
                $jsError = [$form->js()->form('add prompt', $fieldName, $str)];
            }

            return $jsError;
        });
    }

    /**
     * Add a callback when fields are changed. You must supply array of fields
     * that will trigger the callback when changed.
     *
     * @param callable $fx
     * @param array    $fields
     */
    public function onLineChange($fx, $fields)
    {
        if (!is_callable($fx)) {
            throw new Exception('Function is required for onLineChange event.');
        }
        $this->eventFields = $fields;

        $this->changeCb = $fx;
    }

    /**
     * Input field collecting multiple rows of data.
     *
     * @return string
     */
    public function getInput()
    {
        return $this->app->getTag('input', [
            'name' => $this->short_name,
            'type' => 'hidden',
            'value' => $this->getValue(),
            'readonly' => true,
        ]);
    }

    /**
     * Get Multiline initial field value. Value is based on model set and will
     * output data rows as json string value.
     *
     * @return false|string
     */
    public function getValue()
    {
        $model = null;
        // Will load data when using containsMany.
        $data = $this->app->ui_persistence->typecastSaveField($this->field, $this->field->get());

        // If data is empty try to load model data directly. - For hasMany model
        // or array model already populated with data.
        if (empty($data)) {
            // Set model according to model reference if set, or simply the model passed to it.
            if ($this->model->loaded() && $this->modelRef) {
                $model = $this->model->ref($this->modelRef);
            } elseif (!$this->modelRef) {
                $model = $this->model;
            }
            if ($model) {
                foreach ($model as $row) {
                    $d_row = [];
                    foreach ($this->rowFields as $fieldName) {
                        $field = $model->getField($fieldName);
                        if ($field->isEditable()) {
                            $value = $row->get($field->short_name);
                        } else {
                            $value = $this->app->ui_persistence->_typecastSaveField($field, $row->get($field->short_name));
                        }
                        $d_row[$fieldName] = $value;
                    }
                    $data[] = $d_row;
                }
            }
            $data = json_encode($data, JSON_UNESCAPED_UNICODE);
        }

        return $data;
    }

    /**
     * Validate each row and return errors if found.
     *
     * @return array|null
     */
    public function validate(array $rows)
    {
        $rowErrors = [];
        $model = $this->getModel();

        foreach ($rows as $cols) {
            $rowId = $this->getMlRowId($cols);
            foreach ($cols as $fieldName => $value) {
                if ($fieldName === '__atkml' || $fieldName === $model->id_field) {
                    continue;
                }

                try {
                    $field = $model->getField($fieldName);
                    // Save field value only if the field was editable
                    if (!$field->read_only) {
                        $model->set($fieldName, $this->app->ui_persistence->typecastLoadField($field, $value));
                    }
                } catch (\atk4\core\Exception $e) {
                    $rowErrors[$rowId][] = ['field' => $fieldName, 'msg' => $e->getMessage()];
                }
            }
            $rowErrors = $this->addModelValidateErrors($rowErrors, $rowId, $model);
        }

        if ($rowErrors) {
            return $rowErrors;
        }
    }

    /**
     * Save rows.
     */
    public function saveRows()
    {
        // If we are using a reference, make sure main model is loaded.
        if ($this->modelRef && !$this->model->loaded()) {
            throw new Exception('Parent model need to be loaded');
        }

        $model = $this->model;
        if ($this->modelRef) {
            $model = $model->ref($this->modelRef);
        }

        $currentIds = [];
        foreach ($this->getModel() as $id => $data) {
            $currentIds[] = $id;
        }

        foreach ($this->rowData as $row) {
            if ($this->modelRef && $this->linkField) {
                $model->set($this->linkField, $this->model->get('id'));
            }

            foreach ($row as $fieldName => $value) {
                if ($fieldName === '__atkml') {
                    continue;
                }

                if ($fieldName === $model->id_field && $value) {
                    $model->load($value);
                }

                $field = $model->getField($fieldName);
                if ($field->isEditable()) {
                    $field->set($value);
                }
            }
            $id = $model->save()->get($model->id_field);
            $k = array_search($id, $currentIds, true);
            if ($k > -1) {
                unset($currentIds[$k]);
            }

            $model->unload();
        }

        // Delete remaining currentIds
        foreach ($currentIds as $id) {
            $model->delete($id);
        }

        return $this;
    }

    /**
     * Check for model validate error.
     *
     * @return mixed
     */
    protected function addModelValidateErrors($errors, $rowId, $model)
    {
        $e = $model->validate();
        if ($e) {
            foreach ($e as $field => $msg) {
                $errors[$rowId][] = ['field' => $field, 'msg' => $msg];
            }
        }

        return $errors;
    }

    /**
     * for javascript use - changing this method may brake JS functionality.
     *
     * Finds and returns Multiline row id.
     *
     * @return |null
     */
    private function getMlRowId(array $row)
    {
        $rowId = null;
        foreach ($row as $col => $value) {
            if ($col === '__atkml') {
                $rowId = $value;

                break;
            }
        }

        return $rowId;
    }

    /**
     * Will return a model reference if reference was set in setModel.
     * Otherwise, will return main model.
     *
     * @return Model
     */
    public function getModel()
    {
        $model = $this->model;
        if ($this->modelRef) {
            $model = $model->ref($this->modelRef);
        }

        return $model;
    }

    /**
     * Set view model.
     * If modelRef is used then getModel will return proper model.
     *
     * @param array $fields
     *
     * @return Model
     */
    public function setModel(Model $model, $fields = [], $modelRef = null, $linkField = null)
    {
        // Remove Multiline field name from model
        if ($model->hasField($this->short_name)) {
            $model->getField($this->short_name)->never_persist = true;
        }
        $model = parent::setModel($model);

        if ($modelRef) {
            if (!$linkField) {
                throw new Exception('Using model ref required to set $linkField');
            }
            $this->linkField = $linkField;
            $this->modelRef = $modelRef;
            $model = $model->ref($modelRef);
        }

        if (!$fields) {
            $fields = array_keys($model->getFields('not system'));
        }
        $this->rowFields = array_merge([$model->id_field], $fields);

        foreach ($this->rowFields as $fieldName) {
            $this->fieldDefs[] = $this->getFieldDef($model->getField($fieldName));
        }

        return $model;
    }

    /**
     * Return the field definition to use in JS for rendering this field.
     * $component is one of the following html input types:
     * - input
     * - dropdown
     * - checkbox
     * - textarea.
     *
     * Depending on the component, additional data is set to fieldOptions
     * (dropdown needs values, input needs type)
     */
    public function getFieldDef(\atk4\data\Field $field): array
    {
        // Default is input
        $component = 'input';

        // First check in Field->ui['multiline'] if there are settings for Multiline display
        // $test = $field->ui['multiline'];
        if (isset($field->ui['multiline'][0])) {
            $component = $this->_mapComponent($field->ui['multiline'][0]);
        }
        // Next, check if there is a 'standard' UI seed set
        elseif (isset($field->ui['form'][0])) {
            $component = $this->_mapComponent($field->ui['form'][0]);
        }
        // If values or enum property is set, display a Dropdown
        elseif ($field->enum || $field->values || $field->reference instanceof HasOne) {
            $component = 'dropdown';
        }
        // Figure UI FormField type by field type.
        // TODO: Form already does this, maybe use that somehow?
        elseif ($field->type) {
            $component = $this->_mapComponent($field->type);
        }

        return [
            'field' => $field->short_name,
            'component' => $component,
            'caption' => $field->getCaption(),
            'default' => $field->default,
            'isExpr' => isset($field->expr),
            'isEditable' => $field->isEditable(),
            'isHidden' => $field->isHidden(),
            'isVisible' => $field->isVisible(),
            'fieldOptions' => $this->_getFieldOptions($field, $component),
        ];
    }

    /**
     * Maps field type to form control (input, checkbox, dropdown or textarea), defaults to input.
     *
     * @param string|object $fieldType
     */
    protected function _mapComponent($fieldType): string
    {
        if (is_string($fieldType)) {
            switch (strtolower($fieldType)) {
                case 'dropdown':
                case 'enum':
                    return 'dropdown';
                case 'boolean':
                case 'checkbox':
                    return 'checkbox';
                case 'text':
                case 'textarea':
                    return 'textarea';
                default: return 'input';
            }
        }

        // If an object was passed, use its classname as string
        elseif (is_object($fieldType)) {
            return $this->_mapComponent((new \ReflectionClass($fieldType))->getShortName());
        }

        return 'input';
    }

    protected function _getFieldOptions(\atk4\data\Field $field, string $component): array
    {
        $options = [];

        // If additional options are defined for field, add them.
        if (isset($field->ui['multiline']) && is_array($field->ui['multiline'])) {
            $add_options = $field->ui['multiline'];
            if (isset($add_options[0])) {
                if (is_array($add_options[0])) {
                    $options = array_merge($options, $add_options[0]);
                }
                if (isset($add_options[1]) && is_array($add_options[1])) {
                    $options = array_merge($options, $add_options[1]);
                }
            } else {
                $options = array_merge($options, $add_options);
            }
        } elseif (isset($field->ui['form']) && is_array($field->ui['form'])) {
            $add_options = $field->ui['form'];
            if (isset($add_options[0])) {
                unset($add_options[0]);
            }
            $options = array_merge($options, $add_options);
        }

        // Some input types need additional options set, make sure they are there
        switch ($component) {
            // Input needs to have type set (text, number, date etc)
            case 'input':
                if (!isset($options['type'])) {
                    $options['type'] = $this->_addTypeOption($field);
                }

                break;
            // Dropdown needs values set
            case 'dropdown':
                if (!isset($options['values'])) {
                    $options['values'] = $this->_addValuesOption($field);
                }

                break;
        }

        return $options;
    }

    /**
     * HTML input field needs type property set. If it wasnt found in $field->ui,
     * determine from rest.
     */
    protected function _addTypeOption(\atk4\data\Field $field): string
    {
        switch ($field->type) {
            case 'integer':
                return 'number';
            //case 'date':
                //return 'date';
            default:
                return 'text';
        }
    }

    /**
     * Dropdown field needs values set. If it wasnt found in $field->ui, determine
     * from rest.
     */
    protected function _addValuesOption(\atk4\data\Field $field): array
    {
        if ($field->enum) {
            return array_combine($field->enum, $field->enum);
        }
        if ($field->values && is_array($field->values)) {
            return $field->values;
        } elseif ($field->reference) {
            $model = $field->reference->refModel()->setLimit($this->enumLimit);

            return $model->getTitles();
        }

        return [];
    }

    public function renderView()
    {
        if (!$this->getModel()) {
            throw new Exception('Multiline field needs to have it\'s model setup.');
        }

        if ($this->cb->triggered()) {
            $this->cb->set(function () {
                try {
                    return $this->renderCallback();
                } catch (\atk4\Core\Exception $e) {
                    $this->app->terminateJSON(['success' => false, 'error' => $e->getMessage()]);
                } catch (\Error $e) {
                    $this->app->terminateJSON(['success' => false, 'error' => $e->getMessage()]);
                }
            });
        }

        $this->multiLine->template->trySetHTML('Input', $this->getInput());
        parent::renderView();

        $this->multiLine->vue(
            'atk-multiline',
            [
                'data' => [
                    'linesField' => $this->short_name,
                    'fields' => $this->fieldDefs,
                    'idField' => $this->getModel()->id_field,
                    'url' => $this->cb->getJSURL(),
                    'eventFields' => $this->eventFields,
                    'hasChangeCb' => $this->changeCb ? true : false,
                    'options' => $this->options,
                    'rowLimit' => $this->rowLimit,
                    'caption' => $this->caption,
                    'afterAdd' => $this->jsAfterAdd,
                    'afterDelete' => $this->jsAfterDelete,
                    'addOnTab' => $this->addOnTab,
                ],
            ]
        );
    }

    /**
     * For javascript use - changing these methods may brake JS functionality.
     *
     * Render callback.
     */
    private function renderCallback()
    {
        $action = $_POST['__atkml_action'] ?? null;
        $response = [
            'success' => true,
            'message' => 'Success',
        ];

        switch ($action) {
            case 'update-row':
                $model = $this->setDummyModelValue(clone $this->getModel());
                $expressionValues = array_merge($this->getExpressionValues($model), $this->getCallbackValues($model));
                $this->app->terminateJSON(array_merge($response, ['expressions' => $expressionValues]));

                break;
            case 'on-change':
                // Let regular callback render output.
                return call_user_func_array($this->changeCb, [json_decode($_POST['rows'], true), $this->form]);

                break;
        }
    }

    /**
     * For javascript use - changing this method may brake JS functionality.
     *
     * Return values associated with callback field.
     *
     * @return array
     */
    private function getCallbackValues($model)
    {
        $values = [];
        foreach ($this->fieldDefs as $def) {
            $fieldName = $def['field'];
            if ($fieldName === $model->id_field) {
                continue;
            }
            $field = $model->getField($fieldName);
            if ($field instanceof Callback) {
                $value = call_user_func($field->expr, $model);
                $values[$fieldName] = $this->app->ui_persistence->_typecastSaveField($field, $value);
            }
        }

        return $values;
    }

    /**
     * For javascript use - changing this method may brake JS functionality.
     *
     * Looks inside the POST of the request and loads data into model.
     * Allow to Run expression base on rowData value.
     */
    private function setDummyModelValue($model)
    {
        $post = $_POST;

        foreach ($this->fieldDefs as $def) {
            $fieldName = $def['field'];
            if ($fieldName === $model->id_field) {
                continue;
            }

            $value = $post[$fieldName] ?? null;
            if ($model->getField($fieldName)->isEditable()) {
                try {
                    $model->set($fieldName, $value);
                } catch (ValidationException $e) {
                    // Bypass validation at this point.
                }
            }
        }

        return $model;
    }

    /**
     * For javascript use - changing this method may brake JS functionality.
     *
     * Return values associated to field expression.
     *
     * @return array
     */
    private function getExpressionValues($model)
    {
        $dummyFields = [];
        $formatValues = [];

        foreach ($this->getExpressionFields($model) as $k => $field) {
            $dummyFields[$k]['name'] = $field->short_name;
            $dummyFields[$k]['expr'] = $this->getDummyExpression($field, $model);
        }

        if (!empty($dummyFields)) {
            $dummyModel = new Model($model->persistence, ['table' => $model->table]);
            foreach ($dummyFields as $field) {
                $dummyModel->addExpression($field['name'], ['expr' => $f['expr'], 'type' => $model->getField($field['name'])->type]);
            }
            $values = $dummyModel->loadAny()->get();
            unset($values[$model->id_field]);

            foreach ($values as $f => $value) {
                $field = $model->getField($f);
                $formatValues[$f] = $this->app->ui_persistence->_typecastSaveField($field, $value);
            }
        }

        return $formatValues;
    }

    /**
     * For javascript use - changing this method may brake js functionality.
     *
     * Get all field expression in model, but only evaluate expression used in
     * rowFields.
     *
     * @return array
     */
    private function getExpressionFields($model)
    {
        $fields = [];
        foreach ($model->getFields() as $field) {
            if (!$field instanceof Field_SQL_Expression || !in_array($field->short_name, $this->rowFields, true)) {
                continue;
            }

            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * For javascript use - changing this method may brake JS functionality.
     *
     * Return expression where fields are replace with their current or default value.
     * Ex: total field expression = [qty] * [price] will return 4 * 100
     * where qty and price current value are 4 and 100 respectively.
     *
     * @return mixed
     */
    private function getDummyExpression($exprField, $model)
    {
        $expr = $exprField->expr;
        $matches = [];

        preg_match_all('/\[[a-z0-9_]*\]|{[a-z0-9_]*}/i', $expr, $matches);

        foreach ($matches[0] as $match) {
            $fieldName = substr($match, 1, -1);
            $field = $model->getField($fieldName);
            if ($field instanceof Field_SQL_Expression) {
                $expr = str_replace($match, $this->getDummyExpression($field, $model), $expr);
            } else {
                $expr = str_replace($match, $this->getValueForExpression($exprField, $fieldName, $model), $expr);
            }
        }

        return $expr;
    }

    /**
     * For javascript use - changing this method may brake JS functionality.
     *
     * Return a value according to field used in expression and the expression type.
     * If field used in expression is null, the default value is returned.
     *
     * @return int|mixed|string
     */
    private function getValueForExpression($exprField, $fieldName, Model $model)
    {
        switch ($exprField->type) {
            case 'money':
            case 'integer':
            case 'float':
                // Value is 0 or the field value.
                $value = $model->get($fieldName) ?: 0;

                break;
            default:
                $value = '"' . $model->get($fieldName) . '"';
        }

        return $value;
    }
}