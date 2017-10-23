<?php
/**
 * baserCMS :  Based Website Development Project <http://basercms.net>
 * Copyright (c) baserCMS Users Community <http://basercms.net/community/>
 *
 * @copyright		Copyright (c) baserCMS Users Community
 * @link			http://basercms.net baserCMS Project
 * @package			Baser.View
 * @since			baserCMS v 0.1.0
 * @license			http://basercms.net/license/index.html
 */

/**
 * [ADMIN] メールフィールド 一覧　行
 */
$priorities = array('0.1' => '0.1', '0.2' => '0.2', '0.3' => '0.3', '0.4' => '0.4', '0.5' => '0.5',
	'0.6' => '0.6', '0.7' => '0.7', '0.8' => '0.8', '0.9' => '0.9', '1.0' => '1.0');
?>


<?php if (!$data['SearchIndex']['status']): ?>
	<?php $class = ' class="disablerow"' ?>
<?php else: ?>
	<?php $class = '' ?>
<?php endif; ?>
<tr id="Row<?php echo $count + 1 ?>" <?php echo $class; ?>>
	<td class="row-tools" style="width:22%">
		<?php if ($this->BcBaser->isAdminUser()): ?>
			<?php echo $this->BcForm->checkbox('ListTool.batch_targets.' . $data['SearchIndex']['id'], array('type' => 'checkbox', 'class' => 'batch-targets', 'value' => $data['SearchIndex']['id'])) ?>
		<?php endif ?>		
		<?php
		echo $this->BcForm->input('SearchIndex.priority' . '_' . $data['SearchIndex']['id'], array(
			'type' => 'select',
			'options' => $priorities,
			'empty' => false,
			'class' => 'priority',
			'value' => $data['SearchIndex']['priority']))
		?>
<?php $this->BcBaser->link($this->BcBaser->getImg('admin/icn_tool_delete.png', array('alt' => '削除', 'class' => 'btn')), array('action' => 'ajax_delete', $data['SearchIndex']['id']), array('title' => '削除', 'class' => 'btn-delete')) ?>
<?php $this->BcBaser->img('admin/ajax-loader-s.gif', array('id' => 'PriorityAjaxLoader' . $data['SearchIndex']['id'], 'style' => "vertical-align:middle;display:none")) ?>
	</td>
	<td><?php echo $data['SearchIndex']['id'] ?></td>
	<td style="width:15%">
		<?php echo $data['SearchIndex']['type'] ?><br />
		<?php echo $this->BcBaser->link($this->BcText->noValue($data['SearchIndex']['title'], '設定なし'), siteUrl() . preg_replace('/^\//', '', $data['SearchIndex']['url']), array('target' => '_blank')) ?>
	</td>
	<td><?php echo $this->Text->truncate($data['SearchIndex']['detail'], 50) ?></td>
	<td style="width:10%;text-align:center">
		<?php echo $this->BcText->booleanMark($data['SearchIndex']['status']); ?><br />
	</td>
	<?php echo $this->BcListTable->dispatchShowRow($data) ?>
	<td style="width:10%;white-space: nowrap">
<?php echo $this->BcTime->format('Y-m-d', $data['SearchIndex']['created']) ?><br />
<?php echo $this->BcTime->format('Y-m-d', $data['SearchIndex']['modified']) ?>
	</td>
</tr>
