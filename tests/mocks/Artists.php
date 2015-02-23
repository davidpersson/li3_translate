<?php

namespace li3_translate\tests\mocks;

class Artists extends \lithium\data\Model {

	use li3_behaviors\data\model\Behaviors;

	public $validates = [
		'name' => [
			['notEmpty', 'message' => 'Username should not be empty.'],
			['lengthBetween', 'min' => 4, 'max' => 20, 'message' => 'Username should be between 5 and 20 characters.']
		],
	];

	public $_actsAs = [
		'Translatable' => [
			'default' => 'ja',
			'locales' => ['en', 'it', 'ja'],
			'fields' => ['name', 'profile']
		]
	];

}
?>