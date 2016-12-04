<?php
/**
 * Connects regular form with a Agile Data model and imports some fields.
 * It also binds action on form->update(), which will now force model to be updated.
 *
 * In most cases the following use is sufficient
 * $form->setModel('SomeModel');
 *
 * If you want to import fields from multiple models, you can use this:
 *  $ctl = $form->importFields($model, array('name', 'surname');
 *
 * and if you want to use your own class based on this one, syntax is:
 *  $ctl = $form->add('Controller_MVCForm_Derived')
 *              ->importFields($model, array('name', 'surname'));
 *
 * You can subsequently call importField() to add additional fields such as:
 *  $form_field = $ctl->importField('age');
 * which will return newly added form field.
 */
class Controller_ADForm extends AbstractController
{
    /** @var \atk4\data\Model */
    public $model = null;

    /** @var Form */
    public $form = null;

    /**
     * Field associations form_field => model_field
     *
     * @var array
     */
    public $field_associations = array();

    /**
     * Field type associations model_field_type => form_field_type
     *
     * @var array
     */
    public $type_associations = array(
        'string' => 'Line',
        'text' => 'Text',
        'int' => 'Number',
        'integer' => 'Number',
        'numeric' => 'Number',
        'money' => 'Money',
        'real' => 'Number',
        'float' => 'Number',
        'date' => 'ADDatePicker',
        'datetime' => 'ADDateTimePicker',
        'daytime' => 'Time',
        'time' => 'Time',
        'boolean' => 'Checkbox',
        'reference' => 'Readonly',
        'reference_id' => 'DropDown',
        'password' => 'Password',
        'list' => 'DropDown',
        'radio' => 'Radio',
        'readonly' => 'Readonly',
        'image' => 'Image',
        'file' => 'Upload',
        'array' => 'JSONArray',
        'struct' => 'JSON',  // deprecated, used in older versions of AD
        'object' => 'JSON',
    );

    /**
     * Is update hook already set?
     *
     * @var bool
     */
    private $_hook_set = false;

    /** @var Form */
    public $owner;



    /**
     * Initialization.
     */
    public function init()
    {
        parent::init();

        if (! $this->owner->model instanceof \atk4\data\Model) {
            throw $this->exception('Controller_ADForm can only be used with Agile Data \atk4\data\Model models');
        }
    }

    /**
     * Import model fields in form.
     *
     * @param array|string|bool $fields
     */
    public function setActualFields($fields)
    {
        /** @type \atk4\data\Model $this->owner->model */
        $this->importFields($this->owner->model, $fields);
    }


    /**
     * Import model fields in form.
     *
     * Use $fields === false if you want to associate form with model, but don't create form fields.
     *
     * @param \atk4\data\Model $model
     * @param array|string|bool $fields
     *
     * @return void|$this
     */
    public function importFields($model, $fields = null)
    {
        $this->model = $model;
        $this->form = $this->owner;

        if ($fields === false) {
            return;
        }

        if (!$fields) {
            if ($model->only_fields) {
                $fields = $model->only_fields;
            } else {
                $fields = [];
                // get all field-elements
                foreach ($model->elements as $field => $f_object) {
                    if ($f_object instanceof \atk4\data\Field
                        && $f_object->isEditable()
                        && !$f_object->isHidden()
                    ) {
                        $fields[] = $field;
                    }
                }
            }
        }

        if (!is_array($fields)) {
            $fields = [$fields];
        }

        // import fields one by one
        foreach ($fields as $field) {
            $this->importField($field);
        }

        // set update hook
        if (!$this->_hook_set) {
            $this->owner->addHook('update', array($this, 'update'));
            $model->addHook('afterLoad', array($this, 'setFields'));
            $this->_hook_set = true;
        }

        return $this;
    }

    /**
     * Import one field from model into form.
     *
     * @param string $field
     * @param string $field_name
     *
     * @return void|Form_Field
     */
    public function importField($field, $field_name = null)
    {
        $field = $this->model->hasElement($field);
        /** @type \atk4\data\Field $field */

        if (!$field || !$field->isEditable() || $field->isHidden()) {
            return;
        }

        if ($field_name === null) {
            $field_name = $this->_unique($this->owner->elements, $field->short_name);
        }
        $this->field_associations[$field_name] = $field;

        $field_type = $this->getFieldType($field);
        $field_caption = isset($field->ui['caption']) ? $field->ui['caption'] : null;

        // add form field
        $form_field = $this->owner->addField($field_type, $field_name, $field_caption);
        $form_field->set($field->get());

        // set model for hasOne field
        if ($ref_field = $this->model->hasRef($field->short_name)) {
            $form_field->setModel($ref_field->getModel());
        }

        // set model for enum field
        if (isset($field->enum)) {

            $list = isset($field->ui['valueList'])
                ? $field->ui['valueList']
                : array_combine($field->enum, $field->enum);

            if ($form_field instanceof Form_Field_Checkbox) {
                $list = array_reverse($list);
            }


            $form_field->setValueList($list);
        }

        // field value is mandatory
        if ($field->mandatory) {
            $form_field->validateNotNULL($field->mandatory);
        }

        // form field placeholder
        $placeholder = isset($field->ui['placeholder']) ? $field->ui['placeholder'] : /*$field->emptyText() ?:*/ null;
        if ($placeholder) {
            $form_field->setAttr('placeholder', $placeholder);
        }

        // form field hint
        if (isset($field->ui['hint'])) {
            $form_field->setFieldHint($field->ui['hint']);
        }

        // set empty text option for dropdown type form fields if model field is not mandatory
        if ($form_field instanceof Form_Field_ValueList && !$field->mandatory) {
            /** @type string $text */
            //$text = $field->emptyText();
            //$form_field->setEmptyText($text);
            $form_field->setEmptyText('- no value -');
        }

        return $form_field;
    }


    /**
     * Copies model field values into form.
     */
    public function setFields()
    {
        foreach ($this->field_associations as $form_field => $model_field) {
            $this->form->set($form_field, $model_field->get());
        }
    }

    /**
     * Returns array of models model_name => \atk4\data\Model used in this form.
     *
     * @return array
     */
    public function getFields()
    {
        $models = array();

        /**
         * @var Form_Field $form_field
         * @var Field $model_field
         */
        foreach ($this->field_associations as $form_field => $model_field) {
            $v = $this->form->get($form_field);
            $m = $model_field->owner;
            $model_field->set($v);
            if (!isset($models[$m->name])) {
                $models[$m->name] = $m;
            }
        }

        return $models;
    }

    /**
     * Returns form field type associated with model field.
     *
     * Redefine this method to add special handling of your own fields.
     *
     * @param \atk4\data\Field $field
     *
     * @return string
     */
    public function getFieldType($field)
    {
        $type = 'Line';

        // if form field type explicitly set in models UI properties
        if (isset($field->ui['display'])) {
            $tmp = $field->ui['display'];
            if (isset($tmp['form'])) {
                $tmp = $tmp['form'];
            }
            if (is_string($tmp) && $tmp) {
                return $tmp;
            }
        }

        // associate hasOne (Reference_One) fields with DropDown form field
        if ($this->model->hasRef($field->short_name)) {
            return 'DropDown';
        }

        // associate enum fields with DropDown form_field
        if (isset($field->enum) && $field->type != 'boolean') {
            return 'DropDown';
        }

        // try to find associated form field type
        if (isset($this->type_associations[$field->type])) {
            $type = $this->type_associations[$field->type];
        }

        return $type;
    }

    /**
     * Update form model
     *
     * @param Form $form
     */
    public function update($form)
    {
        $models = $this->getFields();
        foreach ($models as $model) {
            $model->save();
        }
    }
}
