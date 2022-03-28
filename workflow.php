<?php
/**
 * @package     Joomla.Plugins
 * @subpackage  Task.Workflow
 *
 * @author     magnus <magnus@chestry.com>
 * @copyright  2022 Magnus Singer
 * @license    GNU General Public License version 2 or later
 * @link       https://chestry.com/magnus
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
 * Plugin to manage scheduler tasks for the workflow component
 * @since     4.X
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
	 * @inheritdoc
	 */
	protected $autoloadLanguage = true;

	/**
	 * @inheritdoc
	 */
	protected $db;

	/**
	 * @inheritdoc
	 */
	protected $app;

	/**
	 * @inheritDoc
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
	 * @since 4.X
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
	 * @since 4.X
	 */
	private function resetToInitialStageForCategory(int $categoryId)
	{
        // Get all articles of the category
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
            // Get the workflow ID of the articles category
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

            // Get the default stage of the workflow
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

            // Update the articles stage to the default one
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
     * Get the workflow ID of a given category
     *
	 * @param int $categoryId the ID of the category
	 *
	 * @return   mixed the ID of the workflow or another flag
	 * @throws Exception
	 * @since 4.X
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
     * Get the ID of the default workflow
     *
	 * @since 4.X
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
     * Get the workflow ID from the parent of the given category
     *
	 * @param   int $categoryId the ID of the category
	 *
	 * @since 4.X
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

        // If the parent also has a flag as workflow ID, we recursively get its parents ID or the default one
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
