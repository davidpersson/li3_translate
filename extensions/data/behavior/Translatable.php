<?php
/**
 * Lithium: the most rad php framework
 *
 * @copyright     Copyright 2011, Union of RAD (http://union-of-rad.org)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */

namespace li3_translate\extensions\data\behavior;

use lithium\util\Set;
use lithium\core\Environment;

/**
 * The `Translateable` class handles all translating MongoDB based content, the data is placed
 * into a language namespace for that record. This also needs to deal with validation to make sure
 * the model acts as expected in all scenarios.
 */
class Translatable extends \li3_behaviors\data\model\Behavior {

	/**
	 * Default configurations.
	 *
	 * The `'default'` option is only necessary if you are saving multiple languages in one
	 * create or save command. A base language of which to gather the content and validate
	 * against is needed. This ensures that your validations will still work. Defaults to
	 * the current effective locale (via `Environment::get('locale')`).
	 *
	 * The `'locales'` that you want to use is fairly self explanatory, it simply tells the plugin
	 * which languages you want support for.  Defaults to the current available locales
	 * (via `Environment::get('locales')`).
	 *
	 * So as not to double up on too much data. The `'fields'` array tells the behavior which
	 * fields will need localizations. Those that are not included here will be simple
	 * fields which will not be attached a locale.
	 *
	 * @var array
	 */
	protected static $_defaults = [
		'default' => null,
		'locales' => [],
		'fields' => []
	];

	protected static function _config($model, $behavior, $config, $defaults) {
		$config += $defaults;

		if (!$config['default']) {
			$config['default'] = Environment::get('locale');
		}
		if (!$config['locales']) {
			$config['locales'] = array_keys(Environment::get('locales'));
		}
		return $config;
	}

	protected static function _filters($model, $behavior) {
		static::_save($model, $behavior);
		static::_find($model, $behavior);
		static::_validates($model, $behavior);
	}

	/**
	 * A protected function to apply our filter to the classes save method.
	 * we add a locale offset to the entity
	 */
	protected static function _save($model, $behavior) {
		$model::applyFilter('save', function($self, $params, $chain) use ($model, $behavior) {
			$entity = $params['entity'];
			$fields = $behavior->config('fields');
			$default = $behavior->config('default');
			$locales = $behavior->config('locales');

			if ($params['data']) {
				$entity->set($params['data']);
				$params['data'] = null;
			}

			// Add errors to locale and return if locale has not been set or locale separated
			// content.
			if (!isset($entity->locale)) {
				$localePresent = array_map(
					function($key) use ($entity) {
						return array_key_exists($key, $entity->data());
					}, $locales);
				if (!in_array(true, $localePresent) && !isset($default)) {
					$entity->errors('locale', 'Locale has not been set.');
					return false;
				}
				if (!in_array(true, $localePresent) && isset($default)) {
					$entity->locale = $default;
				}
			}

			$fields[] = 'locale';
			$entityData = $entity->data();

			$processFields = function($fields, $entityData, $locale) use ($entity) {
				$data = [];
				$entityData['locale'] = $locale;

				// Add to data directly from the entity data or from the presaved localization.
				// Data is only added from the translatable fields
				foreach($fields as $key) {

					// If the key is available
					if(isset($entityData[$key])) {
						$data[$key] = $entityData[$key];
					}

					// If the key part of localizations
					if(isset($entityData[$key]) && isset($entityData['localizations'])) {
						foreach($entityData['localizations'] as $key => $localized) {
							if(isset($localized[$key]) && $localized['locale'] == $locale){
								$data[$key] = $localized[$key];
							}
						}
					}
				}
				return $data;
			};

			// Sort out the data from individual locale save mode and multiple
			// exists. If the localization doesn't exist we add the data to the localization array.
			if (isset($entity->locale)) {
				$validation_locale = $entity->locale;
				$data = $processFields($fields, $entityData, $validation_locale);
			}
			else {
				$validation_locale = $default;
				$entityLocalizedSet = [];
				$saveLocalizations = [];
				foreach($locales as $locale){
					if (isset($entityData[$locale])) {
						$saveLocalizations[] = $locale;
						if ($entity->_id) {
							$entityLocalizedSet[$locale] = $processFields($fields, $entityData[$locale], $locale);
						}
						else{
							$entityLocalizedSet[] = $processFields($fields, $entityData[$locale], $locale);
						}
					}
					unset($entity->$locale);
				}
			}

			// Should the record exist we need overwrite the localized data if the localization already
			// exists. If the localization doesn't exist we add the data to the localization array.
			$localizedSet = [];
			$dbLocalizations = [];
			if ($entity->exists() && $record = $self::find(
				(string) $entity->_id, ['Ignore-Locale'=> true]
			)) {
				foreach($record->localizations as $localization) {
					$locale = $localization->locale;
					$dbLocalizations[] = $locale;
					if (!isset($entityLocalizedSet[$locale]) && $locale != $data['locale']) {
						$localizedSet[] = $localization->to('array');
					}
					else {
						if (isset($entityLocalizedSet[$locale])) {
							$data = $entityLocalizedSet[$locale];
						}
						if (isset($data['localizations'])) {
							unset($data['localizations']);
						}
						$data += $localization->to('array');
						$localizedSet[] = $data;
					}
				}
			}

			// If the locale has not been picked up in previously saved localizations
			// regular save fits into this category.
			if (!isset($entityLocalizedSet) && !in_array($validation_locale, $dbLocalizations)) {
				$localizedSet[] = $data;
			}

			// If saving multiple translations at once
			if (!$entity->_id && isset($entityLocalizedSet)) {
				$localizedSet = $entityLocalizedSet;
			}

			// If updating multiple translations at once, we need to add the translations
			// that are still not yet covered from the update information
			if ($entity->_id  && isset($entityLocalizedSet)) {
				$toAdd = array_diff($saveLocalizations, $dbLocalizations);
				foreach($toAdd as $locale) {
					$localizedSet[] = $entityLocalizedSet[$locale];
				}
			}

			$entity->localizations = $localizedSet;
			$entity->validation = $validation_locale;

			unset($entity->$validation_locale);

			foreach($fields as $key){
				unset($entity->$key);
			}

			$params['entity'] = $entity;

			return $chain->next($self, $params, $chain);
		});
	}

	/**
	 * A protected function to apply our filter to the classes find method.
	 * We grab the document from the documents as needed and pass them to you in language specific
	 * output. If you pass a locale option we return only the document for that locale. If you only
	 * want to search a locale but return all locales then pass locale as a condition.
	 */
	protected static function _find($model, $behavior) {
		$model::applyFilter('find', function($self, $params, $chain) use ($behavior) {
			$fields = $behavior->config('fields');

			if (isset($params['options']['Ignore-Locale'])) {
				unset($params['options']['Ignore-Locale']);
				return $chain->next($self, $params, $chain);
			}

			if (isset($params['options']['locale'])) {
				$params['options']['conditions']['locale'] = $params['options']['locale'];
			}

			// Need to parse the options find options as needed to keep
			$options = static::_parseOptions($params['options'], $fields, $locales);
			$params['options'] = $options;
			$result = $chain->next($self, $params, $chain);

			$options += $fields;

			// If this is an integer result send it back as it is.
			if (is_int($result)) {
				return $result;
			}

			// Otherwise send it to the result parser which will output it as needed.
			$function = static::_formatReturnDocument($options, $fields);
			if ($params['type'] == 'all' || $params['type'] == 'search') {
				$result->each($function);
				return $result;
			}
			return $function($result);
		});
	}

	/**
	 * A protected method to override model validates.
	 * We take a validation key to get get the record we want to validate, this could be hard in the
	 * case of multi locale saving. But I think we really need to do 1 at a time.
	 */
	protected static function _validates($model, $behavior) {
		$model::applyFilter('validates', function($self, $params, $chain) {
			$origEntity = $params['entity'];
			$entity = clone $params['entity'];
			foreach($entity->localizations as $localization) {

				$isValidationLocale = ($localization->locale == $entity->validation);

				if (isset($entity->validation) && $isValidationLocale && is_object($localization)) {
					foreach($localization->data() as $key => $value) {
						$entity->$key = $value;
					}
					unset($entity->localizations);
				}
				$params['entity'] = $entity;
			}
			$result = $chain->next($self, $params, $chain);
			$errors = $params['entity']->errors();
			if (!empty($origEntity)) {
			 $origEntity->errors($params['entity']->errors());
			}
			return $result;
		});
	}

	/**
	 * Returns a closure that formats the returned document to either include all locales
	 * or to just to return the single record output.
	 *
	 * @param array $options Original find options to mainly get the locale needed to return
	 * @param array $fields The fields to which translatability is applies
	 * @return closure Contains logic needed to parse a single result correctly.
	 */
	protected static function _formatReturnDocument($options, $fields) {
		return function($result) use ($options, $fields) {
			if (!is_object($result) && !isset($result->localizations)) {
				return $result;
			}
			foreach($result->localizations as $localization) {
				$localizationData = $localization->data();
				if(!empty($localizationData)) {
					$locale = $localization->locale;
					$fields[] = 'locale';

					if (isset($options['locale']) && $options['locale'] == $locale) {
						foreach($fields as $key){
							$result->$key = $localization->$key;
						}
						return $result;
					}
					$result->$locale = $localization;
				}
			}
			return $result;
		};
	}

	/**
	 * Formats the options to allow for our schema tweaked method of searching data.
	 *
	 * @param array $options Original find options to mainly get the locale needed to return
	 * @param array $fields The fields to which translatability is applies
	 * @return array The parsed options.
	 */
	protected static function _parseOptions($options, $fields, $locales) {
		$subdocument = 'localizations.';
		$array = [];

		foreach ($options as $option => $values) {
			if (is_array($values) && !empty($values)) {
				foreach ($values as $key => $args) {

					// If option has an argument key that starts with a localization
					$hasLocalizedKey = (in_array(true, array_map( function($localization) use ($key) {
						return (strpos($key, $localization . '.') !== false);
					}, $locales)));

					if($hasLocalizedKey) {
						list($locale, $optionKey) = explode('.', $key);
						$array[$option][$subdocument . $optionKey] = $args;
						$array[$option][$subdocument . 'locale'] = $locale;
					}

					// If the option is part of the localized fields
					$isLocalized = (in_array($key, $fields) || $key == 'locale');
					if ($isLocalized) {
						$array[$option][$subdocument . $key] = $args;
					}

					if (!$isLocalized && !$hasLocalizedKey) {
						$array[$option][$key] = $args;
					}
				}
			}
			else {
				$array[$option] = $values;
			}
		}
		return $array;
	}
}

?>