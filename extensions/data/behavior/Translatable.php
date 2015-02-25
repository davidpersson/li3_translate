<?php
/**
 * Lithium: the most rad php framework
 *
 * @copyright     Copyright 2011, Union of RAD (http://union-of-rad.org)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */

namespace li3_translate\extensions\data\behavior;

use Exception;
use lithium\core\Environment;

/**
 * The `Translateable` class handles all translating based content, the data is placed into a
 * i18n namespace for that record. This also needs to deal with validation to make sure the
 * model acts as expected in all scenarios.
 */
class Translatable extends \li3_behaviors\data\model\Behavior {

	/**
	 * Default configurations.
	 *
	 * @var array
	 */
	protected static $_defaults = [
		'locale' => null,
		'locales' => [],
		'fields' => [],
		'strategy' => null // either `inline` or `nested`
	];

	protected static function _config($model, $behavior, $config, $defaults) {
		$config += $defaults;

		if (!$config['locale']) {
			$config['locale'] = Environment::get('locale');
		}
		if (!$config['locales']) {
			$config['locales'] = array_keys(Environment::get('locales'));
		}
		if (!$config['strategy']) {
			$connection = get_class($model::connection());
			$config['strategy'] = $connection::enabled('arrays') ? 'nested' : 'inline';
		}

		if ($config['strategy'] === 'inline') {
			foreach ($config['fields'] as $field) {
				foreach ($config['locales'] as $locale) {
					if ($locale === $config['locale']) {
						continue;
					}
					if (!$model::hasField($field = static::_composeField($field, $locale))) {
						throw new Exception("Model `{$model}` is missing translation field `{$field}`");
					}
				}
			}
		}
		return $config;
	}

	protected static function _filters($model, $behavior) {
		static::_save($model, $behavior);
		static::_find($model, $behavior);
		static::_validates($model, $behavior);
	}

	public function translate($model, $behavior, $entity, $field, $locale = null, $value = null) {
		$config = $behavior->config();

		if (!in_array($field, $config['fields'])) {
			throw new Exception("Field `{$field}` in model `{$model}` not available for translation.");
		}
		if ($locale === null) {
			$result = $entity->i18n;
			$result[$locale] = $entity->{$field};

			return $result;
		}
		if (!in_array($locale, $config['locales'])) {
			throw new Exception("Locale `{$locale}` not setup for translation of field `{$field}` in `{$model}`.");
		}
		if ($value === null) {
			if (!isset($entity->i18n[$field][$locale])) {
				// Prefer i18n over original.

				if ($locale === $config['locale']) {
					return $entity->{$field};
				}
				return null;
			}
			return $entity->i18n[$field][$locale];
		}
		if (!isset($entity->i18n)) {
			// FIXME Move this into create filter?
			$entity->i18n = [];
		}
		$entity->i18n[$field][$locale] = $value;

		$entity = static::_sync($entity, $config['fields'], $config['locale']);
	}

	public function isTranslated($model, $behavior, $entity, $field) {
		if (!in_array($field, $behavior->config('fields'))) {
			throw new Exception("Field `{$field}` in model `{$model}` not available for translation.");
		}
		return !empty($entity->i18n[$field]);
	}

	protected static function _save($model, $behavior) {
		$model::applyFilter('save', function($self, $params, $chain) use ($model, $behavior) {
			$entity =& $params['entity'];

			if ($params['data']) {
				$entity->set($params['data']);
				$params['data'] = null;
			}

			// When no i18n is present, we don't have to do anything.
			if (!$entity->i18n) {
				return $chain->next($self, $params, $chain);
			}
			$config = $behavior->config();
			$entity = static::_sync($entity, $config['fields'], $config['locale']);

			// Should the record exist we need overwrite the localized data if the localization already
			// exists. If the localization doesn't exist we add the data to the localization array.
			$key = $model::key();

			if ($entity->exists() && $config['strategy'] == 'nested') {
				$original = $model::find('first', [
					'conditions' => [$key => $entity->{$key}],
					'fields' => $config['fields'],
					'translate' => true
				]);

				// Augment the to-be-saved translation with existing ones.
				// Otherwise Mongo would override the entire array.
				foreach ($original->i18n as $field => $translations) {
					foreach ($translations as $locale => $value) {
						if (empty($entity->i18n[$field][$locale])) {
							$entity->i18n[$field][$locale] = $value;
						}
					}
				}
			}
			$entity = static::_thin($entity, $config['fields'], $config['locale']);
			// After thinning there might not be anything left.

			// Map back fields for inline strategy.
			if ($config['strategy'] === 'inline') {
				foreach ($entity->i18n as $field => $locales) {
					foreach ($locales as $locale => $value) {
						// After thinning the default locale isn't
						// contained inside the i18n key anymore. So
						// we just have to handle one case here.
						$inline = static::_composeField($field, $locale);
						$entity->{$inline} = $value;
					}
				}
				unset($entity->i18n);
			}
			return $chain->next($self, $params, $chain);
		});
	}

	protected static function _find($model, $behavior) {
		$model::applyFilter('find', function($self, $params, $chain) use ($behavior) {
			$config = $behavior->config();

			if (isset($params['options']['translate'])) {
				if ($params['options']['translate'] === false) {
					unset($params['options']['translate']);
					return $chain->next($self, $params, $chain);
				}
			}
			// Rewrite all dot syntaxed paths conditions when using the inline
			// strategy, mapping to actual field names. Models using the nested
			// strategy are assumed to *natively* handle these conditions i.e MongoDB.
			//
			// FIXME Currently supports just 1-level deep condition simple keys.
			if (isset($params['options']['conditions']) && $config['strategy'] === 'inline') {
				$conditions = [];

				foreach ($params['options']['conditions'] as $key => $value) {
					$regex = '/i18n\.([a-z0-9_]+)\.([a-z_]{2,5})/is';

					if (strpos($key, 'i18n.') === 0 && preg_match($regex, $key, $matches)) {
						$key = static::_composeField($matches[1], $matches[2]);
					}
					$conditions[$key] = $value;
				}
				$params['options']['conditions'] = $conditions;
			}
			$result = $chain->next($self, $params, $chain);

			$format = function($entity) use ($config) {
				if ($config['strategy'] === 'nested') {
					$entity->i18n = $entity->i18n->data();
				} else {
					$entity->i18n = [];
				}
				foreach ($config['fields'] as $field) {
					foreach ($config['locales'] as $locale) {
						if ($locale === $config['locale']) {
							$entity->i18n[$field][$locale] = $entity->{$field};
							// The nested strategy should already have all translation ready
							// in the i18n array except the non-redundant fields.
						} elseif ($config['strategy'] === 'inline') {
							$inline = static::_composeField($field, $locale);
							$entity->i18n[$field][$locale] = $entity->{$inline};

							// In order to lower confusion as what happens assinging
							// values to inline fields directly we'll remove them here.
							unset($entity->{"i18n_{$field}_{$locale}"});
						}
					}
				}
			};
			if (is_object($result) && is_a($result, '\lithium\data\Collection')) {
				return $result->each($format);
			}
			return $format($result);
		});
	}

	protected static function _validates($model, $behavior) {
		$model::applyFilter('validates', function($self, $params, $chain) {
			if (!$entity->i18n) {
				// When no i18n is present, we don't have to do anything.
				return $chain->next($self, $params, $chain);
			}
			$config = $behavior->config();
			$entity = static::_sync($entity, $config['fields'], $config['locale']);

			// Validate original fields, as well as any translation
			// that are present. By default translation are sparse
			// and cannot be *required*.
			$rules =& $params['options']['rules'];

			foreach ($config['fields'] as $field) {
				if (!isset($rules[$field])) {
					continue;
				}
				foreach ($config['locales'] as $locale) {
					if ($locale === $config['locale']) {
						continue;
					}
					$inline = static::_composeField($field, $locale, '.');

					if (isset($rules[$inline])) {
						continue;
					}
					$rules[$inline] = $rules[$field];
				}
			}
			foreach ($rules as $field => $rule) {
				if (strpos($field, 'i18n') === false) {
					continue;
				}
				foreach ($rule as &$r) {
					$r['required'] = false;
				}
			}
			return $chain->next($self, $params, $chain);
		});
	}

	// Sync in both directions.
	protected function _sync($entity, $fields, $locale) {
		foreach ($entity->i18n as $key => $value) {
			foreach ($value as $k => $v) {
				if ($k === $locale) {
					$entity->{$key} = $v;
				}
			}
		}
		foreach ($fields as $field) {
			if (!empty($entity->{$field})) {
				$entity->i18n[$field][$locale] = $entity->{$field};
			}
		}
		return $entity;
	}

	// In order to not save redundant data, we'll remove
	// the default locale translations from the i18n array.
	protected function _thin($entity, $fields, $locale) {
		foreach ($fields as $field) {
			if (isset($entity->i18n[$field][$locale])) {
				$entity->{$field} = $entity->i18n[$field][$locale];
				unset($entity->i18n[$field][$locale]);
			}
			if (empty($entity->i18n[$field])) {
				unset($entity->i18n[$field]);
			}
		}
		return $entity;
	}

	protected static function _composeField($field, $locale, $separator = '_') {
		return "i18n{$separator}{$field}{$separator}{$locale}";
	}
}

?>