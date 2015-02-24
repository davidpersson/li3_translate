# Translatable Behavior
### for the Lithium PHP Framework

What this behavior does is enable you to have content of different locales/languages to be stored in your MongoDB database via your lithium based model. You can also search and retrieve locale specific data simply. 

* At this moment the plugin is only compatible with MongoDB.

If somebody wanted to make it adaptable then other data sources could be supported in the future.

## Installation

Install the plugin via composer (this will also pull in any dependencies):
```shell
composer require davidpersson/li3_translate
```

## Usage

In the model you wish to have translatable please add something to the tune of:

```php
// ...
class Artists extends \lithium\data\Model {

   use li3_behaviors\data\model\Behaviors;

   protected static $_actsAs = [
       'Translatable' => [
           'default' => 'ja',
           'locales' => ['en', 'it', 'ja'],
           'fields' => ['name']
       ]
   ];
	
   // ...
```

* The default option is required, espececially if you are saving multiple languages in one create or save command. A base language of which to gather the content and validate against is needed. This ensures that your validations will still work.

* The locales that you want to use is fairly self explanatory, it simply tells the plugin which languages you want support for.

* So as not to double up on too much data. The fields array tells the behavior which fields will need localizations. Those that are not included here will be simple fields which will not be attached a locale.

Good example usage of the plugin can be seen in the unit tests, but here is a brief description.

## Saving Data

When saving data with the default locale, you basically don't have to change anything. When saving translated data along with the original data use one of the following syntax (all are equivalent):

```php
$user = Users::create([
	'profile' => 'Dreaded Rasta',
	'name' => 'Richard',
	'i18n.name.it' => 'Ricardo'
]);

$user = Users::create([
	'name' => 'Richard',
	'profile' => 'Dreaded Rasta',
	'i18n' => [
		'name' => [
			'it' => 'Ricardo'
		]
	]
]);

$user = Users::create([
	'profile' => 'Dreaded Rasta', 
	'name' => 'Richard'
]);
$user->translate('name', 'it', 'Ricardo');
```

When _saving just translated data_ i.e. when updating an already existing record use the following syntax. Please note that in this case original data (for the default locale must already be present).

```php
$user = Users::find('first', ['conditions' => ['name' => 'Richard']]);

$user->save([
	'i18n.name.it' => 'Ricardo'
]);

// ... or ...

$user->translate('name', 'it', 'Ricardo');
$user->save();
```

## Retrieving translated Entities

```php
$user = Users::find('first', [
	'conditions' => ['i18n.name.it' => 'Ricardo']
]);

$user = Users::find('all', [
	'order' => ['i18n.name.it' => 'ASC']
]);
```

If you don't want to use the `translate()` method to translate single fields, but
want the record translated into a single locale use the following syntax. You can
then retrieve field data as normal.

```php
$user = Users::find('first', [
	'conditions' => ['id' => 23],
	'translate' => 'it'
]);

$user->name; // returns 'Ricardo'.
```

This is good for display purposes. For saving
data use the syntax described above.

If you do not know the translation you are searching for, the translation can be searched by the following:

```
$users = Users::all(['conditions' => ['i18n.name' => 'Ricardo']]);
```

## On-the-fly Disabling of Translations

You can disable the automatic retrieval of translations for a record:
```php
$user = Users::find('first', [
	'conditions' => ['name' => 'Richard'], 
	'translate' => false
]);
```

And disable running the behavior on save:
```php
$user->save(null, ['translate' => false]);
```

## Accessing Translations

```php
$user = Users::find('first', ['conditions' => ['name' => 'Richard']]);

$user->translate('name', 'it'); // returns 'Ricardo';
$user->translate('name'); // returns ['en' => 'Richard', 'it' => 'Ricardo'];
$user->name; // returns 'Richard', as the default locale is `en`.
```

## Validation

When translations are present in the to-be-saved data, all are validated against the base rule.

```php
$user = Users::create([
	'profile' => 'Dreaded Rasta', 
	'name' => 'Richard'
]);
$user->validate(['translate' => false]);

```


## Data Model

Translation data is stored inline with the entity. For MongoDB a subdocument will used, for relational databases special field names are used. 

`<user>`
	- `name => Richard`
	- `profile`
	- `<i18n>`
		- `name`
			- `it => Ricardo`

`<user>`
	- `name => Richard`
	- `profile`
	- `i18n_name_it => Ricardo`

## Gotchas

You should not change the locale when the model already has saved data. Otherwise manual
migration will be required.

Bugs etc
--------

I have yet tested this plugin for white lists and other features. If you find a case that doesn't work then please log an issue.
