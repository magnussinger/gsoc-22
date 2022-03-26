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
use Joomla\Registry\Registry;


// TODO: multiple categories + fixing

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
	 */
	protected $db;

	/**
	 * @var JApplicationCms;
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
	 */
	protected function setToInitialStage(ExecuteTaskEvent $event): int
	{
		$categoryId = $event->getArgument('params')->targetCat;

		$model = $this->app->bootComponent('com_content')->getMVCFactory()->createModel(
			'Articles',
			'Administrator',
			['ignore_request' => true]
		);
		$model->setState('list.select', 'a.id, a.catid');
		$model->setState('filter.category_id', $categoryId);
		$articles = $model->getItems();

		foreach ($articles as $article)
		{
			$this->logTask($article->id);

			$workflowId = $this->getWorkflowId($categoryId);

			if ($workflowId == 'use_default')
			{
				$workflowId = $this->getDefaultWorkflowId();
			}
			elseif ($workflowId == 'use_inherited')
			{
				$workflowId = $this->getParentWorkflowId($categoryId);
			}

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

			$initialStageId = (int) $this->db->setQuery($query);
			$values = [$initialStageId, $article->id];

			$this->logTask($initialStageId);

			$updateQuery = $this->db->getQuery(true);
			$updateQuery->update($this->db->quoteName('#__workflow_associations', 'map'))
				->set($this->db->quoteName('map.stage_id') . ' = :stageID')
				->where(
					[
						$this->db->quoteName('map.item_id') . ' = :itemID',
						$this->db->quoteName('map.extension') . " = 'com_content.article'",
					]
				)
				->bind([':workflowID', ':itemID'], $values, ParameterType::LARGE_OBJECT);
			$this->db->setQuery($updateQuery);
			$this->db->loadObject();
		}

		return TaskStatus::OK;
	}

	/**
	 * @param   int $categoryId the ID of the category
	 * @return   mixed the ID of the workflow or another flag
	 */
	private function getWorkflowId(int $categoryId)
	{
		$query = $this->db->getQuery(true);
		$query->select('params')
			->from($this->db->quoteName('#__categories', 'map'))
			->where($this->db->quoteName('map.id') . ' = :id')
			->bind(':id', $categoryId, ParameterType::INTEGER);

		$this->db->setQuery($query);
		$categoryParams = $this->db->loadObject();

		$categoryParamsRegistry = new Registry;
		$categoryParamsRegistry->loadObject($categoryParams);

		return $categoryParamsRegistry->get('workflow_id');
	}

	/**
	 * @return integer the ID of the default workflow
	 */
	private function getDefaultWorkflowId(): int
	{
		$query = $this->db->getQuery(true);
		$query->select('id')
			->from($this->db->quoteName('#__workflows', 'map'))
			->where($this->db->quoteName('map.default') . ' = 1');
		$this->db->setQuery($query);

		return (int) $this->db->loadObject();
	}

	/**
	 * @param   int $categoryId the ID of the category
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

		$parentId = (string) $this->db->loadObject();
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
