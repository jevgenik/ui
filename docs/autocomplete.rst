
.. _autocomplete:

=========================
AutoComplete Form Control
=========================

.. php:namespace:: atk4\ui\Form\Control
.. php:class:: AutoComplete

Agile UI uses "Form\\Control\\Dropdown" by default on the form, but there is also implementation
for AutoComplete form control. Although they look similar, there are some differences:

 - AutoComplete will perform callback to fetch values.
 - AutoComplete can use callback to format options (both keys and values).
 - AutoComplete can search in multiple fields.
 - AutoComplete can use form current (dirty) values to apply dependency and limit options.
 - AutoComplete can have multiple selection.
 - AutoComplete has additional feature called "Plus"
 - AutoComplete only works with models. Won't work for pre-defined value lists.

AutoComplete can be a drop-in replacement for Dropdown. 

Using Plus mode
---------------

In your application, it is handy if you can automatically add a missing "client" from the form
where you add an invoice. AutoComplete implements "Plus" mode which will automatically open a modal
form where you can enter new record details.

The form save will re-use the model of your auto-complete, so be sure to set() defaults and
addCondition()s::

    $form->addControl('test', [\atk4\ui\Form\Control\AutoComplete::class, 'plus'=>true])->setModel(new Country($db));

Specifying in Model
-------------------

You can also specify that you prefer to use AutoComplete inside your model definition::

    $model->hasOne('country_id', [new Country($db), 'ui'=>['form'=>[\atk4\ui\Form\Control\AutoComplete::class]]]);

Advanced Usage
--------------

You can do much more with AutoComplete form control by passing dropdown settings::

    $form->addControl('test', [
        \atk4\ui\Form\Control\AutoComplete::class, 
        'settings'=>[
            'allowReselection' => true,
            'selectOnKeydown' => false,
            'onChange'        => new \atk4\ui\JsExpression('function(value,t,c){
                                    if ($(this).data("value") !== value) {
                                    $(this).parents(".form").form("submit");
                                    $(this).data("value", value);
                                   }}'),
        ]
    ])->setModel(new Country($db));


Lookup Form Control
===================

In 1.6 we have introduced Lookup form control, which is identical to AutoComplete but additionally allows
use of Filters::


    $form = \atk4\ui\Form::addTo($app, ['segment']);
    \atk4\ui\Label::addTo($form, ['Add city', 'top attached'], ['AboveControls']);

    $l = $form->addControl('city',[\atk4\ui\Form\Control\Lookup::class]);

    // will restraint possible city value in droddown base on country and/or language.
    $l->addFilter('country', 'Country');
    $l->addFilter('language', 'Lang');

    // make sure country and language belong to your model.
    $l->setModel(new City($db));

Possibly this feature will be introduced into "AutoComplete" class.
