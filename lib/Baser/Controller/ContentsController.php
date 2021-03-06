<?php
/**
 * baserCMS :  Based Website Development Project <http://basercms.net>
 * Copyright (c) baserCMS Users Community <http://basercms.net/community/>
 *
 * @copyright		Copyright (c) baserCMS Users Community
 * @link			http://basercms.net baserCMS Project
 * @package			Baser.Controller
 * @since			baserCMS v 4.0.0
 * @license			http://basercms.net/license/index.html
 */

App::uses('BcContentsController', 'Controller');

/**
 * 統合コンテンツ管理 コントローラー
 *
 * baserCMS内のコンテンツを統合的に管理する
 *
 * @package Baser.Controller
 * @property Content $Content
 * @property BcAuthComponent $BcAuth
 * @property SiteConfig $SiteConfig
 * @property Site $Site
 * @property User $User
 * @property BcContentsComponent $BcContents
 */
class ContentsController extends AppController {

/**
 * モデル
 *
 * @var array
 */
	public $uses = ['Content', 'Site', 'SiteConfig', 'ContentFolder'];

/**
 * コンポーネント
 *
 * @var array
 */
	public $components = array('Cookie', 'BcAuth', 'BcAuthConfigure', 'BcContents' => array('useForm' => true));

	public function beforeFilter() {
		parent::beforeFilter();
		$this->BcAuth->allow('view');
	}
	
/**
 * コンテンツ一覧
 *
 * @param integer $parentId
 * @param void
 */
	public function admin_index() {

		switch($this->request->action) {
			case 'admin_index':
				$this->pageTitle = 'コンテンツ一覧';
				break;
			case 'admin_trash_index':
				$this->pageTitle = 'ゴミ箱';
				break;
		}
		
		$this->setViewConditions('Content', ['default' => [
			'named' => [
				'num'		=> $this->siteConfigs['admin_list_num'],
				'site_id'	=> 0,
				'list_type'	=> 1,
				'sort'		=> 'id',
				'direction' => 'asc'
			]
		]]);
		
		if(empty($this->request->params['named']['sort'])) {
			$this->request->params['named']['sort'] = $this->passedArgs['sort'];
		}
		if(empty($this->request->params['named']['direction'])) {
			$this->request->params['named']['direction'] = $this->passedArgs['direction'];
		}
		
		$sites = $this->Site->getSiteList();
		if($sites) {
			if(!$this->passedArgs['site_id'] || !in_array($this->passedArgs['site_id'], array_keys($sites))) {
				reset($sites);
				$this->passedArgs['site_id'] = key($sites);
			}
		} else {
			$this->passedArgs['site_id'] = null;
		}
		
		$this->request->data['ViewSetting']['site_id'] = $currentSiteId = $this->passedArgs['site_id'];
		$this->request->data['ViewSetting']['list_type'] = $currentListType = $this->passedArgs['list_type'];

		if ($this->request->is('ajax')) {
			$template = null;
			$datas = [];
			switch($this->request->params['action']) {
				case 'admin_index':
					switch($currentListType) {
						case 1:
							$conditions = $this->_createAdminIndexConditionsByTree($currentSiteId);
							$datas = $this->Content->find('threaded', ['order' => ['Content.lft'], 'conditions' => $conditions, 'recursive' => 0]);
							// 並び替え最終更新時刻をリセット
							$this->SiteConfig->resetContentsSortLastModified();
							$template = 'ajax_index_tree';
							break;
						case 2:
							$conditions = $this->_createAdminIndexConditionsByTable($currentSiteId, $this->request->data);
							$options = [
								'order' => 'Content.' . $this->passedArgs['sort'] . ' ' . $this->passedArgs['direction'],
								'conditions' => $conditions,
								'limit' => $this->passedArgs['num'],
								'recursive' => 2
							];

							// EVENT Contents.searchIndex
							$event = $this->dispatchEvent('searchIndex', array(
								'options' => $options
							));
							if ($event !== false) {
								$options = ($event->result === null || $event->result === true) ? $event->data['options'] : $event->result;
							}

							$this->paginate = $options;
							$datas = $this->paginate('Content');
							$this->set('authors', $this->User->getUserList());
							$template = 'ajax_index_table';
							break;
					}
					break;
				case 'admin_trash_index':
					$this->Content->Behaviors->unload('SoftDelete');
					$conditions = $this->_createAdminIndexConditionsByTrash();
					$datas = $this->Content->find('threaded', ['order' => ['Content.site_id', 'Content.lft'], 'conditions' => $conditions, 'recursive' => 0]);
					$template = 'ajax_index_trash';
					break;
			}
			$this->set('datas', $datas);
			Configure::write('debug', 0);
			$this->render($template);
			return;
		}
		$this->ContentFolder->getEventManager()->attach($this->ContentFolder);
		$this->set('editInIndexDisabled', false);
		$this->set('contentTypes', $this->BcContents->getTypes());
		$this->set('authors', $this->User->getUserList());
		$this->set('folders', $this->Content->getContentFolderList((int) $currentSiteId, ['conditions' => ['Content.site_root' => false]]));
		$this->set('listTypes', [1 => 'ツリー形式', 2 => '表形式']);
		$this->set('sites', $sites);
		$this->search = 'contents_index';
		$this->subMenuElements = ['contents'];
		$this->help = 'contents_index';

	}

/**
 * ツリー表示用の検索条件を生成する
 *
 * @return array
 */
	protected function _createAdminIndexConditionsByTree($currentSiteId) {
		if($currentSiteId === 'all') {
			$conditions = ['or' => [
				['Site.use_subdomain' => false],
				['Content.site_id' => 0]
			]];
		} else {
			$conditions = ['Content.site_id' => $currentSiteId];
		}
		return $conditions;
	}

/**
 * テーブル表示用の検索条件を生成する
 *	
 * @return array
 */
	protected function _createAdminIndexConditionsByTable($currentSiteId, $data) {
		$data['Content'] = array_merge([
			'name' => '',
			'folder_id' => '',
			'author_id' => '',
			'self_status' => '',
			'type' => ''
		], $data['Content']);
		
		$conditions = ['Content.site_id' => $currentSiteId];
		if($data['Content']['name']) {
			$conditions['or'] = [
				'Content.name LIKE' => '%' . $data['Content']['name'] . '%',
				'Content.title LIKE' => '%' . $data['Content']['name'] . '%'
			];
		}
		if($data['Content']['folder_id']) {
			$content = $this->Content->find('first', ['fields' => ['lft', 'rght'], 'conditions' => ['Content.id' => $data['Content']['folder_id']], 'recursive' => -1]);
			$conditions['Content.rght <'] = $content['Content']['rght'];
			$conditions['Content.lft >'] = $content['Content']['lft'];
		}
		if($data['Content']['author_id']) {
			$conditions['Content.author_id'] = $data['Content']['author_id']; 
		}
		if($data['Content']['self_status'] !== '') {
			$conditions['Content.self_status'] = $data['Content']['self_status'];
		}
		if($data['Content']['type']) {
			$conditions['Content.type'] = $data['Content']['type'];
		}
		return $conditions;
	}

/**
 * ゴミ箱用の検索条件を生成する
 *
 * @return array
 */
	protected function _createAdminIndexConditionsByTrash() {
		return [
			'Content.deleted' => true
		];
	}

/**
 * ゴミ箱内のコンテンツ一覧を表示する
 */
	public function admin_trash_index() {
		$this->setAction('admin_index');
		if (empty($this->request->isAjax)) {
			$this->render('index');
		}
	}

/**
 * ゴミ箱のコンテンツを戻す
 *
 * @return mixed Site Id Or false
 */
	public function admin_ajax_trash_return() {
		if(empty($this->request->data['id'])) {
			$this->ajaxError(500, '無効な処理です。');
		}
		$this->autoRender = false;

		// EVENT Contents.beforeTrashReturn
		$this->dispatchEvent('beforeTrashReturn', [
			'data' => $this->request->data['id']
		]);
		
		$siteId = $this->Content->trashReturn($this->request->data['id']);

		// EVENT Contents.afterTrashReturn
		$this->dispatchEvent('afterTrashReturn', [
			'data' => $this->request->data['id']
		]);
		
		return $siteId;
	}

/**
 * 新規コンテンツ登録（AJAX）
 *
 * @return void
 */
	public function admin_add($alias = false) {

		if(!$this->request->data) {
			$this->ajaxError(500, '無効な処理です。');
		}

		$srcContent = array();
		if($alias) {
			if($this->request->data['Content']['alias_id']) {
				$conditions = array('id' => $this->request->data['Content']['alias_id']);
			} else {
				$conditions = array(
					'plugin' => $this->request->data['Content']['plugin'],
					'type' => $this->request->data['Content']['type']
				);
			}
			$srcContent = $this->Content->find('first', array('conditions' => $conditions, 'recursive' => -1));
			if($srcContent) {
				$this->request->data['Content']['alias_id'] = $srcContent['Content']['id'];
				$srcContent = $srcContent['Content'];
			}

			if(empty($this->request->data['Content']['parent_id']) && !empty($this->request->data['Content']['url'])) {
				$this->request->data['Content']['parent_id'] = $this->Content->copyContentFolderPath($this->request->data['Content']['url'], $this->request->data['Content']['site_id']);
			}

		}

		$user = $this->BcAuth->user();
		$this->request->data['Content']['author_id'] = $user['id'];
		$this->Content->create(false);
		$data = $this->Content->save($this->request->data);
		if($data) {
			if($alias) {
				$message = Configure::read('BcContents.items.' . $this->request->data['Content']['plugin'] . '.' . $this->request->data['Content']['type'] . '.title') .
					'「' . $srcContent['title'] . '」のエイリアス「' . $this->request->data['Content']['title'] . '」を追加しました。';
			} else {
				$message = Configure::read('BcContents.items.' . $this->request->data['Content']['plugin'] . '.' . $this->request->data['Content']['type'] . '.title') . '「' . $this->request->data['Content']['title'] . '」を追加しました。';
			}
			$this->setMessage($message, false, true, false);
			echo json_encode($data['Content']);
		} else {
			$this->ajaxError(500, '保存中にエラーが発生しました。');
		}
		exit();
	}

/**
 * コンテンツ編集
 *
 * @return void
 */
	public function admin_edit() {
		$this->pageTitle = 'コンテンツ編集';
		if(!$this->request->data) {
			$this->request->data = $this->Content->find('first', array('conditions' => array('Content.id' => $this->request->params['named']['content_id'])));
			if(!$this->request->data) {
				$this->setMessage('無効な処理です。', true);
				$this->redirect(['plugin' => false, 'admin' => true, 'controller' => 'contents', 'action' => 'index']);
			}
		} else {
			if($this->Content->save($this->request->data)) {
				$message = Configure::read('BcContents.items.' . $this->request->data['Content']['plugin'] . '.' . $this->request->data['Content']['type'] . '.title') . '「' . $this->request->data['Content']['title'] . '」を更新しました。';
				$this->setMessage($message, false, true);
				$this->redirect(array(
					'plugin'	=> null,
					'controller'=> 'contents',
					'action'	=> 'edit',
					'content_id' => $this->request->params['named']['content_id'],
					'parent_id' => $this->request->params['named']['parent_id']
				));
			} else {
				$this->setMessage('保存中にエラーが発生しました。入力内容を確認してください。', true, true);
			}
		}
		$this->set('publishLink', $this->request->data['Content']['url']);
	}

	/**
	 * エイリアスを編集する
	 *
	 * @param string $plugin
	 * @param string $type
	 */
	public function admin_edit_alias($id) {

		$this->pageTitle = 'エイリアス編集';
		if(!$this->request->data) {
			$this->request->data = $this->Content->find('first', array('conditions' => array('Content.id' => $id)));
			if(!$this->request->data) {
				$this->setMessage('無効な処理です。', true);
				$this->redirect(['plugin' => false, 'admin' => true, 'controller' => 'contents', 'action' => 'index']);
			}
			$srcContent = $this->Content->find('first', array('conditions' => array('Content.id' => $this->request->data['Content']['alias_id']), 'recursive' => -1));
			$srcContent = $srcContent['Content'];
		} else {
			if($this->Content->save($this->request->data)) {
				$srcContent = $this->Content->find('first', array('conditions' => array('Content.id' => $this->request->data['Content']['alias_id']), 'recursive' => -1));
				$srcContent = $srcContent['Content'];
				$message = Configure::read('BcContents.items.' . $srcContent['plugin'] . '.' . $srcContent['type'] . '.title') .
					'「' . $srcContent['title'] . '」のエイリアス「' . $this->request->data['Content']['title'] . '」を編集しました。';
				$this->setMessage($message, false, true);
				$this->redirect(array(
					'plugin'	=> null,
					'controller'=> 'contents',
					'action'	=> 'edit_alias',
					$id
				));
			} else {
				$this->setMessage('保存中にエラーが発生しました。入力内容を確認してください。', true, true);
			}

		}

		$this->set('srcContent', $srcContent);
		$this->BcContents->settingForm($this, $this->request->data['Content']['site_id'], $this->request->data['Content']['id']);
		$this->set('publishLink', $this->request->data['Content']['url']);

	}

/**
 * コンテンツ削除（論理削除）
 *
 * @return boolean
 */
	public function admin_ajax_delete() {
		$this->autoRender = false;
		if(empty($this->request->data['contentId'])) {
			$this->ajaxError(500, '無効な処理です。');
		}
		if($this->_delete($this->request->data['contentId'], false)) {
			return true;
		} else {
			$this->ajaxError(500, '削除中にエラーが発生しました。');
		}
		return false;
	}

/**
 * コンテンツ削除（論理削除）
 */
	public function admin_delete() {
		if(empty($this->request->data['Content']['id'])) {
			$this->notFound();
		}
		if($this->_delete($this->request->data['Content']['id'], true)) {
			$this->redirect(array('plugin' => false, 'admin' => true, 'controller' => 'contents', 'action' => 'index'));
		} else {
			$this->setMessage('削除中にエラーが発生しました。', true, true);
		}
	}

/**
 * コンテンツを削除する（論理削除）
 * 
 * ※ エイリアスの場合は直接削除
 * 
 * @param int $id
 * @param bool $isAlias
 * @param bool $useFlashMessage
 * @return bool
 */
	protected function _delete($id, $useFlashMessage = false) {
		$content = $this->Content->find('first', ['conditions' => ['Content.id' => $id], 'recursive' => -1]);
		if(!$content) {
			return false;
		}
		$content = $content['Content'];
		$typeName = Configure::read('BcContents.items.' . $content['plugin'] . '.' . $content['type'] . '.title');

		// EVENT Contents.beforeDelete
		$this->dispatchEvent('beforeDelete', [
			'data' => $id
		]);
		
		if(!$content['alias_id']) {
			$result = $this->Content->softDeleteFromTree($id);
			$message = $typeName . '「' . $content['title'] . '」をゴミ箱に移動しました。';
		} else {
			$softDelete = $this->Content->softDelete(null);
			$this->Content->softDelete(false);
			$result = $this->Content->removeFromTree($id, true);
			$this->Content->softDelete($softDelete);
			$message = $typeName . 'のエイリアス「' . $content['title'] . '」を削除しました。';
		}
		if($result) {
			$this->setMessage($message, false, true, $useFlashMessage);
		}

		// EVENT Contents.afterDelete
		$this->dispatchEvent('afterDelete', [
			'data' => $id
		]);
		
		return $result;
	}
	
/**
 * 一括削除
 *
 * @param array $ids
 * @return boolean
 * @access protected
 */
	protected function _batch_del($ids) {
		if ($ids) {
			foreach ($ids as $id) {
				$this->_delete($id, false);
			}
		}
		return true;
	}

/**
 * 一括公開
 *
 * @param array $ids
 * @return boolean
 * @access protected
 */
	protected function _batch_publish($ids) {
		if ($ids) {
			foreach ($ids as $id) {
				$this->_changeStatus($id, true);
			}
		}
		return true;
	}

/**
 * 一括非公開
 *
 * @param array $ids
 * @return boolean
 * @access protected
 */
	protected function _batch_unpublish($ids) {
		if ($ids) {
			foreach ($ids as $id) {
				$this->_changeStatus($id, false);
			}
		}
		return true;
	}

	/**
	 * 公開状態を変更する
	 *
	 * @return bool
	 */
	public function admin_ajax_change_status() {
		$this->autoRender = false;
		if(!$this->request->data) {
			$this->ajaxError(500, '無効な処理です。');
		}
		switch($this->request->data['status']) {
			case 'publish':
				$result = $this->_changeStatus($this->request->data['contentId'], true);
				break;
			case 'unpublish':
				$result = $this->_changeStatus($this->request->data['contentId'], false);
				break;
		}
		return $result;
	}

/**
 * 公開状態を変更する
 * 
 * @param int $id
 * @param bool $status
 * @return bool|mixed
 */
	protected function _changeStatus($id, $status) {
		$content = $this->Content->find('first', ['conditions' => ['Content.id' => $id], 'recursive' => -1]);
		if(!$content) {
			return false;
		}
		unset($content['Content']['lft']);
		unset($content['Content']['rght']);
		$content['Content']['self_publish_begin'] = '';
		$content['Content']['self_publish_end'] = '';
		$content['Content']['self_status'] = $status;
		return (bool) $this->Content->save($content, false);
	}
	
/**
 * ゴミ箱を空にする
 *
 * @return bool
 */
	public function admin_ajax_trash_empty() {
		if(!$this->request->data) {
			$this->notFound();
		}
		$this->autoRender = false;
		$this->Content->softDelete(false);
		$contents = $this->Content->find('all', array('conditions' => array('Content.deleted'), 'order' => array('Content.plugin', 'Content.type'), 'recursive' => -1));
		$result = true;

		// EVENT Contents.beforeTrashEmpty
		$this->dispatchEvent('beforeTrashEmpty', [
			'data' => $contents
		]);
		
		if($contents) {
			foreach($contents as $content) {
				if(!empty($this->BcContents->settings['items'][$content['Content']['type']]['routes']['delete'])) {
					$route = $this->BcContents->settings['items'][$content['Content']['type']]['routes']['delete'];	
				} else {
					$route = $this->BcContents->settings['items']['Default']['routes']['delete'];
				}
				if(!$this->requestAction($route, array('data' => array(
					'contentId' => $content['Content']['id'],
					'entityId' => $content['Content']['entity_id'],
				)))) {
					$result = false;
				}
			}
		}

		// EVENT Contents.afterTrashEmpty
		$this->dispatchEvent('afterTrashEmpty', [
			'data' => $result
		]);
		
		return $result;
	}

/**
 * コンテンツ表示
 *
 * @param $plugin
 * @param $type
 */
	public function view($plugin, $type) {
		$data = array('Content' => $this->request->params['Content']);
        if($this->BcContents->preview && $this->request->data) {
            $data = $this->request->data;
        }
		$this->set('data', $data);
		if(!$data['Content']['alias_id']) {
			$this->set('editLink', array('admin' => true, 'plugin' => '', 'controller' => 'contents', 'action' => 'edit', 'content_id' => $data['Content']['id']));
		} else {
			$this->set('editLink', array('admin' => true, 'plugin' => '', 'controller' => 'contents', 'action' => 'edit_alias', $data['Content']['id']));
		}
	}

/**
 * リネーム
 *
 * 新規登録時の初回リネーム時は、name にも保存する
 */
	public function admin_ajax_rename() {
		$this->autoRender = false;
		if(!$this->request->data) {
			$this->ajaxError(500, '無効な処理です。');
		}
		$data = ['Content' => [
			'id'		=> $this->request->data['id'],
			'title'		=> $this->request->data['newTitle'],
			'parent_id' => $this->request->data['parentId'],
			'type'		=> $this->request->data['type'],
			'site_id'	=> $this->request->data['siteId']
		]];
		if($this->Content->save($data, array('firstCreate' => !empty($this->request->data['first'])))) {
			$message = Configure::read('BcContents.items.' . $this->request->data['plugin'] . '.' . $this->request->data['type'] . '.title') .
						'「' . $this->request->data['oldTitle'] . '」を「' . $this->request->data['newTitle'] . '」に名称変更しました。';
			$this->setMessage($message, false, true, false);
			Configure::write('debug', 0);
			return $this->Content->getUrlById($this->Content->id);
		} else {
			$this->ajaxError(500, '名称変更中にエラーが発生しました。');
		}
		return false;
	}

/**
 * 並び順を移動する
 */
	public function admin_ajax_move() {

		$this->autoRender = false;
		if(!$this->request->data) {
			$this->ajaxError(500, '無効な処理です。');
		}
		$this->Content->id = $this->request->data['currentId'];
		if (!$this->Content->exists()) {
			$this->ajaxError(500, 'データが存在しません。');
		}

		if($this->SiteConfig->isChangedContentsSortLastModified($this->request->data['listDisplayed'])) {
			$this->ajaxError(500, "コンテンツ一覧を表示後、他のログインユーザーがコンテンツの並び順を更新しました。<br>一度リロードしてから並び替えてください。");
		}
		
		if(!$this->Content->isMovable($this->request->data['currentId'], $this->request->data['targetParentId'])) {
			$this->ajaxError(500, "同一URLのコンテンツが存在するため処理に失敗しました。");
		}
		
		// EVENT Contents.beforeMove
		$event = $this->dispatchEvent('beforeMove', array(
			'data' => $this->request->data
		));
		if ($event !== false) {
			$this->request->data = $event->result === true ? $event->data['data'] : $event->result;
		}
		
		$data = $this->request->data;
		$result = $this->Content->move(
			$data['currentId'],
			$data['currentParentId'],
			$data['targetSiteId'],
			$data['targetParentId'],
			$data['targetId']
		);

		
		if($data['currentParentId'] == $data['targetParentId']) {
			// 親が違う場合は、Contentモデルで更新してくれるが同じ場合更新しない仕様の為ここで更新する
			$this->SiteConfig->updateContentsSortLastModified();	
		}
		
		if($result) {

			// EVENT Contents.afterAdd
			$this->dispatchEvent('afterMove', array(
				'data' => $result
			));
			
			return json_encode($this->Content->getUrlById($result['Content']['id'], true));
		} else {
			$this->ajaxError(500, 'データ保存中にエラーが発生しました。');
		}

	}

/**
 * 指定したURLのパス上のコンテンツでフォルダ以外が存在するか確認
 *
 * @return mixed
 */
	public function admin_exists_content_by_url() {
		$this->autoRender = false;
		if(!$this->request->data['url']) {
			$this->ajaxError(500, '無効な処理です。');
		}
		Configure::write('debug', 0);
		return $this->Content->existsContentByUrl($this->request->data['url']);
	}

/**
 * 指定したIDのコンテンツが存在するか確認する
 * ゴミ箱のものは無視
 *
 * @param $id
 */
	public function admin_ajax_exists($id) {
		$this->autoRender = false;
		Configure::write('debug', 0);
		return $this->Content->exists($id);
	}

/**
 * プラグイン等と関連付けられていない素のコンテンツをゴミ箱より消去する
 * 
 * @param $id
 * @return bool
 */
	public function admin_empty() {
		if(empty($this->request->data['contentId'])) {
			return false;
		}
		$softDelete = $this->Content->softDelete(null);
		$this->Content->softDelete(false);
		$result = $this->Content->removeFromTree($this->request->data['contentId'], true);
		$this->Content->softDelete($softDelete);
		return $result;
	}

/**
 * サイトに紐付いたフォルダリストを取得
 *
 * @param $siteId
 */
	public function admin_ajax_get_content_folder_list($siteId) {
		$this->autoRender = false;
		Configure::write('debug', 0);
		return json_encode($this->Content->getContentFolderList((int) $siteId, ['conditions' => ['Content.site_root' => false]]));
	}

/**
 * コンテンツ情報を取得する
 */
	public function admin_ajax_contents_info() {
		$this->autoLayout = false;
		$sites = $this->Site->getPublishedAll();
		foreach($sites as $key => $site) {
			$sites[$key]['published'] = $this->Content->find('count', ['conditions' => ['Content.site_id' => $site['Site']['id'], 'Content.status' => true]]);
			$sites[$key]['unpublished'] = $this->Content->find('count', ['conditions' => ['Content.site_id' => $site['Site']['id'], 'Content.status' => false]]);
			$sites[$key]['total'] = $sites[$key]['published'] + $sites[$key]['unpublished'];
		}
		$this->set('sites', $sites);
	}
	
	public function admin_ajax_get_full_url($id) {
		$this->autoRender = false;
		Configure::write('debug', 0);
		return $this->Content->getUrlById($id, true);
	}
}