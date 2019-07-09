<?php
/**
 * @version    SVN: <svn_id>
 * @package    Com_Tjucm
 * @author     Techjoomla <extensions@techjoomla.com>
 * @copyright  Copyright (c) 2009-2017 TechJoomla. All rights reserved.
 * @license    GNU General Public License version 2 or later.
 */

// No direct access
defined('_JEXEC') or die;

jimport('joomla.application.component.view');
jimport('joomla.application.component.controller');
jimport('joomla.filesystem.file');
jimport('joomla.database.table');

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

/**
 * View to edit
 *
 * @since  1.6
 */
class TjucmViewItemform extends JViewLegacy
{
	/**
	 * The JForm object
	 *
	 * @var  Form
	 */
	protected $form;

	/**
	 * The active item
	 *
	 * @var  object
	 */
	protected $item;

	/**
	 * The model state
	 *
	 * @var  object
	 */
	protected $state;

	/**
	 * The model state
	 *
	 * @var  object|array
	 */
	protected $params;

	/**
	 * @var  boolean
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected $canSave;

	/**
	 * The Record Id
	 *
	 * @var  Int
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected $id;

	/**
	 * The Copy Record Id
	 *
	 * @var  Int
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected $copyRecId;

	/**
	 * Display the view
	 *
	 * @param   string  $tpl  Template name
	 *
	 * @return void
	 *
	 * @throws Exception
	 */
	public function display($tpl = null)
	{
		$app  = Factory::getApplication();
		$input = $app->input;
		$user = Factory::getUser();
		$this->state   = $this->get('State');
		$this->id = $input->getInt('id', $input->getInt('content_id', 0));

		// Include models
		JModelLegacy::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_tjucm/models');

		/* Get model instance here */
		$model = $this->getModel();
		$model->setState('item.id', $this->id);

		$this->item    = $this->get('Data');
		$this->params  = $app->getParams('com_tjucm');
		$this->canSave = $this->get('CanSave');
		$this->form = $this->get('Form');
		$this->client = $input->get('client');

		$clusterId = $input->getInt('cluster_id', 0);

		// Set cluster_id in request parameters
		if ($this->id && !$clusterId)
		{
			$input->set('cluster_id', $this->item->cluster_id);
		}

		// Get a copy record id
		$this->copyRecId = (int) $app->getUserState('com_tjucm.edit.itemform.data.copy_id', 0);

		// Check copy id set and empty request id record
		if ($this->copyRecId && !$this->id)
		{
			$this->id = $this->copyRecId;
		}

		// Code check cluster Id of URL with saved cluster_id both are equal in edit mode
		if (!$this->copyRecId && $this->id)
		{
			$clusterId = $input->getInt("cluster_id", 0);

			if ($clusterId != $this->item->cluster_id)
			{
				$app->enqueueMessage(Text::_('JERROR_ALERTNOAUTHOR'), 'error');
				$app->setHeader('status', 403, true);

				return;
			}
		}

		// If did not get the client from url then get if from menu param
		if (empty($this->client))
		{
			// Get the active item
			$menuItem = $app->getMenu()->getActive();

			// Get the params
			$this->menuparams = $menuItem->params;

			if (!empty($this->menuparams))
			{
				$this->ucm_type   = $this->menuparams->get('ucm_type');

				if (!empty($this->ucm_type))
				{
					$this->client     = 'com_tjucm.' . $this->ucm_type;
				}
			}
		}

		if (empty($this->client))
		{
			$app->enqueueMessage(Text::_('COM_TJUCM_ITEM_DOESNT_EXIST'), 'error');
			$app->setHeader('status', 404, true);

			return;
		}

		// Check the view access to the itemform (the model has already computed the values).
		if ($this->item->params->get('access-view') == false)
		{
			$app->enqueueMessage(Text::_('JERROR_ALERTNOAUTHOR'), 'error');
			$app->setHeader('status', 403, true);

			return;
		}

		// Get if user is allowed to save the content
		$tjUcmModelType = JModelLegacy::getInstance('Type', 'TjucmModel');

		$typeId = $tjUcmModelType->getTypeId($this->client);

		$typeData = $tjUcmModelType->getItem($typeId);

		// Check if the UCM type is unpublished
		if ($typeData->state == "0")
		{
			$app->enqueueMessage(Text::_('COM_TJUCM_ITEM_DOESNT_EXIST'), 'error');
			$app->setHeader('status', 404, true);

			return;
		}

		// Set Layout to type view
		$layout = isset($typeData->params['layout']) ? $typeData->params['layout'] : '';
		$this->setLayout($layout);

		$allowedCount = $typeData->allowed_count;
		$userId = $user->id;

		if (empty($this->id))
		{
			$this->allowedToAdd = $model->allowedToAddTypeData($userId, $this->client, $allowedCount);

			if (!$this->allowedToAdd)
			{
				JLoader::import('controllers.itemform', JPATH_SITE . '/components/com_tjucm');
				$itemFormController = new TjucmControllerItemForm;
				$itemFormController->redirectToListView($typeId, $allowedCount);
			}
		}

		$view = explode('.', $this->client);

		// Call to extra fields
		$this->form_extra = $model->getFormExtra(
		array(
			"clientComponent" => 'com_tjucm',
			"client" => $this->client,
			"view" => $view[1],
			"layout" => 'edit',
			"content_id" => $this->id)
			);

		// Check if draft save is enabled for the form
		JTable::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_tjucm/tables');
		$tjUcmTypeTable = JTable::getInstance('Type', 'TjucmTable');
		$tjUcmTypeTable->load(array('unique_identifier' => $this->client));
		$typeParams = json_decode($tjUcmTypeTable->params);

		$this->allow_auto_save = (isset($typeParams->allow_auto_save) && empty($typeParams->allow_auto_save)) ? 0 : 1;
		$this->allow_draft_save = (isset($typeParams->allow_draft_save) && !empty($typeParams->allow_draft_save)) ? 1 : 0;

		// Check for errors.
		if (count($errors = $this->get('Errors')))
		{
			throw new Exception(implode("\n", $errors));
		}

		// Ucm triggger before item form display
		JPluginHelper::importPlugin('tjucm');
		$dispatcher = JDispatcher::getInstance();
		$dispatcher->trigger('tjucmOnBeforeItemFormDisplay', array(&$this->item, &$this->form_extra));

		$this->_prepareDocument();

		parent::display($tpl);
	}

	/**
	 * Prepares the document
	 *
	 * @return void
	 *
	 * @throws Exception
	 */
	protected function _prepareDocument()
	{
		$app   = Factory::getApplication();
		$menus = $app->getMenu();
		$title = null;

		// Because the application sets a default page title,
		// we need to get it from the menu item itself
		$menu = $menus->getActive();

		if ($menu)
		{
			$this->params->def('page_heading', $this->params->get('page_title', $menu->title));
		}
		else
		{
			$this->params->def('page_heading', Text::_('COM_TJUCM_DEFAULT_PAGE_TITLE'));
		}

		$title = $this->params->get('page_title', '');

		if (empty($title))
		{
			$title = $app->get('sitename');
		}
		elseif ($app->get('sitename_pagetitles', 0) == 1)
		{
			$title = Text::sprintf('JPAGETITLE', $app->get('sitename'), $title);
		}
		elseif ($app->get('sitename_pagetitles', 0) == 2)
		{
			$title = Text::sprintf('JPAGETITLE', $title, $app->get('sitename'));
		}

		$this->document->setTitle($title);

		if ($this->params->get('menu-meta_description'))
		{
			$this->document->setDescription($this->params->get('menu-meta_description'));
		}

		if ($this->params->get('menu-meta_keywords'))
		{
			$this->document->setMetadata('keywords', $this->params->get('menu-meta_keywords'));
		}

		if ($this->params->get('robots'))
		{
			$this->document->setMetadata('robots', $this->params->get('robots'));
		}
	}
}