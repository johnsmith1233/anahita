<?php defined('KOOWA') or die('Restricted access');?>

<data name="title">
	<?= sprintf(@text('COM-PAGES-STORY-PAGE-ADD'), @name($subject), @link($object), @possessive($target));?>
</data>

<data name="body">
	<?= @helper('text.truncate', @htmlspecialchars($object->excerpt, ENT_QUOTES), array('length'=>200)); ?>
</data>
