<?php
/**
 * @package    Workflow
 *
 * @author     magnus <your@email.com>
 * @copyright  2022 Magnus Singer
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 * @link       http://your.url.com
 */

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;
use Joomla\Event\SubscriberInterface;
use Joomla\Component\Scheduler\Administrator\Task\Status as TaskStatus;

/**
 * Workflow plugin.
 *
 * @package   Workflow
 * @since     1.0.0
 */
class PlgTaskWorkflow extends CMSPlugin implements SubscriberInterface
{
	use TaskPluginTrait;

	/**
	 * @var string[]
	 *
	 * @since 4.1.0
	 */
	protected const TASKS_MAP = [
		'workflow.setCatInitial' => [
			'langConstPrefix' => 'PLG_TASK_WORKFLOW_TASK_SET_TO_INITIAL',
			'form'            => 'workflowTaskForm',
			'method'          => 'setToInitialStage',
		],
	];

	/**
	 * @var boolean
	 * @since 4.1.0
	 */
	protected $autoloadLanguage = true;

	/**
	 * @var DatabaseDriver;
	 * @since 4.2
	 */
	protected $db;

	/**
	 * @var JApplicationCms;
	 * @since 4.2
	 */
	protected $app;

	/**
	 * @inheritDoc
	 *
	 * @return string[]
	 *
	 * @since 4.1.0
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'onTaskOptionsList'    => 'advertiseRoutines',
			'onExecuteTask'        => 'standardRoutineHandler',
			'onContentPrepareForm' => 'enhanceTaskItemForm',
		];
	}

	/**
	 * Plugin method is the array value in the getSubscribedEvents method
	 * The plugin then modifies the Event object (if it's not immutable)
	 *
	 * @param   ExecuteTaskEvent $event the event
	 *
	 * @return   integer  the result code of the execution
	 * @throws Exception
	 *
	 * @since 4.2
	 */
	protected function setToInitialStage(ExecuteTaskEvent $event): int
	{
		$params = $event->getArgument('params');
		$categories = $params->targets;
		foreach ($categories as $categoryId)
		{
			$this->resetToInitialStageForCategory((int) $categoryId);
		}

		return TaskStatus::OK;
	}

	/**
	 * Changes the stage of all articles inside the given category to their initial stage
	 *
	 * @param   int  $categoryId the ID of the category whose articles should be changed
	 *
	 * @throws Exception
	 * @since 2.1
	 */
	private function resetToInitialStageForCategory(int $categoryId)
	{
		$model = $this->app->bootComponent('com_content')->getMVCFactory()->createModel(
			'Articles',
			'Administrator',
			['ignore_request' => true]
		);
		$model->setState('list.select', 'a.id');
		$model->setState('filter.category_id', $categoryId);
		$articles = $model->getItems();

		foreach ($articles as $article)
		{
			$workflowId = $this->getWorkflowId($categoryId);

			if ($workflowId == 'use_default')
			{
				$workflowId = $this->getDefaultWorkflowId();
			}
			elseif ($workflowId == 'use_inherited')
			{
				$workflowId = $this->getParentWorkflowId($categoryId);
			}

			// Cast to int when we got the final id
			$workflowId = (int) $workflowId;

			$query = $this->db->getQuery(true);
			$query->select('id')
				->from($this->db->quoteName('#__workflow_stages', 'map'))
				->where(
					[
						$this->db->quoteName('map.workflow_id') . ' = :workflowID',
						$this->db->quoteName('map.default') . ' = 1',
					]
				)
				->bind(':workflowID', $workflowId, ParameterType::INTEGER);

			$this->db->setQuery($query);
			$result = (array) $this->db->loadObject();
			$targetStageId = $result['id'];

			$query = $this->db->getQuery(true);
			$fields = array(
				$this->db->quoteName('stage_id') . ' = ' . $targetStageId,
			);
			$conditions = array(
				$this->db->quoteName('item_id') . ' = ' . $article->id,
				$this->db->quoteName('extension') . ' = ' . $this->db->quote('com_content.article')
			);
			$query->update($this->db->quoteName('#__workflow_associations'))->set($fields)->where($conditions);
			$this->db->setQuery($query);
			$this->db->execute();
		}
	}

	/**
	 * @param int $categoryId the ID of the category
	 *
	 * @return   mixed the ID of the workflow or another flag
	 * @throws Exception
	 * @since 4.2
	 *
	 */
	private function getWorkflowId(int $categoryId)
	{
		$query = $this->db->getQuery(true)
			->select('params')
			->from($this->db->quoteName('#__categories', 'map'))
			->where($this->db->quoteName('map.id') . ' = :id')
			->bind(':id', $categoryId, ParameterType::INTEGER);
		$this->db->setQuery($query);

		$result = (array) $this->db->loadObject();
		$categoryParams = $result['params'];
		$paramsJson = json_decode($categoryParams, true);

		return $paramsJson['workflow_id'];
	}

	/**
	 * @since 4.2
	 * @return integer the ID of the default workflow
	 */
	private function getDefaultWorkflowId(): int
	{
		$query = $this->db->getQuery(true);
		$query->select('id')
			->from($this->db->quoteName('#__workflows', 'map'))
			->where($this->db->quoteName('map.default') . ' = 1');
		$this->db->setQuery($query);

		$result = (array) $this->db->loadObject();

		return (int) $result['id'];
	}

	/**
	 * @param   int $categoryId the ID of the category
	 *
	 * @since 4.2
	 *
	 * @throws Exception
	 *
	 * @return   integer the ID of the parent workflow
	 */
	private function getParentWorkflowId(int $categoryId): int
	{
		$query = $this->db->getQuery(true);
		$query->select('parent_id')
			->from($query->quoteName('#__categories', 'map'))
			->where($this->db->quoteName('map.id') . ' = :id')
			->bind(':id', $categoryId, ParameterType::INTEGER);

		$this->db->setQuery($query);

		$result = (array) $this->db->loadObject();

		$parentId = (string) $result['parent_id'];
		$workflowId = $this->getWorkflowId($parentId);

		if ($workflowId == 'use_default')
		{
			$workflowId = $this->getDefaultWorkflowId();
		}
		elseif ($workflowId == 'use_inherited')
		{
			$workflowId = $this->getParentWorkflowId($parentId);
		}

		return $workflowId;
	}
}
