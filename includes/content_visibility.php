<?php
/**
*
* @package phpbb
* @copyright (c) 2012 phpBB Group
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
*
*/

/**
* @ignore
*/
if (!defined('IN_PHPBB'))
{
	exit;
}

/**
* phpbb_visibility
* Handle fetching and setting the visibility for topics and posts
* @package phpbb
*/
class phpbb_content_visibility
{
	/**
	* Can the current logged-in user soft-delete posts?
	*
	* @param $forum_id		int		Forum ID whose permissions to check
	* @param $poster_id		int		Poster ID of the post in question
	* @param $post_locked	bool	Is the post locked?
	* @return bool
	*/
	static function can_soft_delete($forum_id, $poster_id, $post_locked)
	{
		global $auth, $user;

		if ($auth->acl_get('m_softdelete', $forum_id))
		{
			return true;
		}
		else if ($auth->acl_get('f_softdelete', $forum_id) && $poster_id == $user->data['user_id'] && !$post_locked)
		{
			return true;
		}

		return false;
	}

	/**
	* Create topic/post visibility SQL for a given forum ID
	*
	* Note: Read permissions are not checked.
	*
	* @param $mode			string	Either "topic" or "post"
	* @param $forum_id		int		The forum id is used for permission checks
	* @param $table_alias	string	Table alias to prefix in SQL queries
	* @return string	The appropriate combination SQL logic for topic/post_visibility
	*/
	static public function get_visibility_sql($mode, $forum_id, $table_alias = '')
	{
		global $auth;

		if ($auth->acl_get('m_approve', $forum_id))
		{
			return '1 = 1';
		}

		return $table_alias . $mode . '_visibility = ' . ITEM_APPROVED;
	}

	/**
	* Create topic/post visibility SQL for a set of forums
	*
	* Note: Read permissions are not checked. Forums without read permissions
	*		should not be in $forum_ids
	*
	* @param $mode			string	Either "topic" or "post"
	* @param $forum_ids		array	Array of forum ids which the posts/topics are limited to
	* @param $table_alias	string	Table alias to prefix in SQL queries
	* @return string	The appropriate combination SQL logic for topic/post_visibility
	*/
	static public function get_forums_visibility_sql($mode, $forum_ids = array(), $table_alias = '')
	{
		global $auth, $db;

		$where_sql = '(';

		$approve_forums = array_intersect($forum_ids, array_keys($auth->acl_getf('m_approve', true)));

		if (sizeof($approve_forums))
		{
			// Remove moderator forums from the rest
			$forum_ids = array_diff($forum_ids, $approve_forums);

			if (!sizeof($forum_ids))
			{
				// The user can see all posts/topics in all specified forums
				return $db->sql_in_set($table_alias . 'forum_id', $approve_forums);
			}
			else
			{
				// Moderator can view all posts/topics in some forums
				$where_sql .= $db->sql_in_set($table_alias . 'forum_id', $approve_forums) . ' OR ';
			}
		}
		else
		{
			// The user is just a normal user
			return "$table_alias{$mode}_visibility = " . ITEM_APPROVED . '
				AND ' . $db->sql_in_set($table_alias . 'forum_id', $forum_ids, false, true);
		}

		$where_sql .= "($table_alias{$mode}_visibility = " . ITEM_APPROVED . '
			AND ' . $db->sql_in_set($table_alias . 'forum_id', $forum_ids) . '))';

		return $where_sql;
	}

	/**
	* Create topic/post visibility SQL for all forums on the board
	*
	* Note: Read permissions are not checked. Forums without read permissions
	*		should be in $exclude_forum_ids
	*
	* @param $mode				string	Either "topic" or "post"
	* @param $exclude_forum_ids	array	Array of forum ids which are excluded
	* @param $table_alias		string	Table alias to prefix in SQL queries
	* @return string	The appropriate combination SQL logic for topic/post_visibility
	*/
	static public function get_global_visibility_sql($mode, $exclude_forum_ids = array(), $table_alias = '')
	{
		global $auth, $db;

		$where_sqls = array();

		$approve_forums = array_diff(array_keys($auth->acl_getf('m_approve', true)), $exclude_forum_ids);

		if (sizeof($exclude_forum_ids))
		{
			$where_sqls[] = '(' . $db->sql_in_set($table_alias . 'forum_id', $exclude_forum_ids, true) . "
				AND $table_alias{$mode}_visibility = " . ITEM_APPROVED . ')';
		}
		else
		{
			$where_sqls[] = "$table_alias{$mode}_visibility = " . ITEM_APPROVED;
		}

		if (sizeof($approve_forums))
		{
			$where_sqls[] = $db->sql_in_set($table_alias . 'forum_id', $approve_forums);
			return '(' . implode(' OR ', $where_sqls) . ')';
		}

		// There is only one element, so we just return that one
		return $where_sqls[0];
	}

	/**
	* Change visibility status of one post or all posts of a topic
	*
	* @param $visibility	int		Element of {ITEM_APPROVED, ITEM_DELETED}
	* @param $post_id		mixed	Post ID or array of post IDs to act on,
	*								if it is empty, all posts of topic_id will be modified
	* @param $topic_id		int		Topic where $post_id is found
	* @param $forum_id		int		Forum where $topic_id is found
	* @param $user_id		int		User performing the action
	* @param $time			int		Timestamp when the action is performed
	* @param $reason		string	Reason why the visibilty was changed.
	* @param $is_starter	bool	Is this the first post of the topic changed?
	* @param $is_latest		bool	Is this the last post of the topic changed?
	* @param $limit_visibility	mixed	Limit updating per topic_id to a certain visibility
	* @param $limit_delete_time	mixed	Limit updating per topic_id to a certain deletion time
	* @return array		Changed post data, empty array if an error occured.
	*/
	static public function set_post_visibility($visibility, $post_id, $topic_id, $forum_id, $user_id, $time, $reason, $is_starter, $is_latest, $limit_visibility = false, $limit_delete_time = false)
	{
		global $db;

		if (!in_array($visibility, array(ITEM_APPROVED, ITEM_DELETED)))
		{
			return array();
		}

		if ($post_id)
		{
			if (is_array($post_id))
			{
				$where_sql = $db->sql_in_set('post_id', array_map('intval', $post_id));
			}
			else
			{
				$where_sql = 'post_id = ' . (int) $post_id;
			}
			$where_sql .= ' AND topic_id = ' . (int) $topic_id;
		}
		else
		{
			$where_sql = 'topic_id = ' . (int) $topic_id;

			// Limit the posts to a certain visibility and deletion time
			// This allows us to only restore posts, that were approved
			// when the topic got soft deleted. So previous soft deleted
			// and unapproved posts are still soft deleted/unapproved
			if ($limit_visibility !== false)
			{
				$where_sql .= ' AND post_visibility = ' . (int) $limit_visibility;
			}
			if ($limit_delete_time !== false)
			{
				$where_sql .= ' AND post_delete_time = ' . (int) $limit_delete_time;
			}
		}

		$sql = 'SELECT poster_id, post_id, post_postcount, post_visibility
			FROM ' . POSTS_TABLE . '
			WHERE ' . $where_sql;
		$result = $db->sql_query($sql);

		$post_ids = $poster_postcounts = $postcounts = $postcount_visibility = array();
		while ($row = $db->sql_fetchrow($result))
		{
			$post_ids[] = (int) $row['post_id'];

			if ($row['post_visibility'] != $visibility)
			{
				if ($row['post_postcount'] && !isset($poster_postcounts[$row['poster_id']]))
				{
					$poster_postcounts[$row['poster_id']] = 1;
				}
				else if ($row['post_postcount'])
				{
					$poster_postcounts[$row['poster_id']]++;
				}

				if (!isset($postcount_visibility[$row['post_visibility']]))
				{
					$postcount_visibility[$row['post_visibility']] = 1;
				}
				else
				{
					$postcount_visibility[$row['post_visibility']]++;
				}
			}
		}
		$db->sql_freeresult($result);

		if (empty($post_ids))
		{
			return array();
		}

		$data = array(
			'post_visibility'		=> (int) $visibility,
			'post_delete_user'		=> (int) $user_id,
			'post_delete_time'		=> ((int) $time) ?: time(),
			'post_delete_reason'	=> truncate_string($reason, 255, 255, false),
		);

		$sql = 'UPDATE ' . POSTS_TABLE . '
			SET ' . $db->sql_build_array('UPDATE', $data) . '
			WHERE ' . $db->sql_in_set('post_id', $post_ids);
		$db->sql_query($sql);

		// Group the authors by post count, to reduce the number of queries
		foreach ($poster_postcounts as $poster_id => $num_posts)
		{
			$postcounts[$num_posts][] = $poster_id;
		}

		// Update users postcounts
		foreach ($postcounts as $num_posts => $poster_ids)
		{
			if ($visibility == ITEM_DELETED)
			{
				$sql = 'UPDATE ' . USERS_TABLE . '
					SET user_posts = 0
					WHERE ' . $db->sql_in_set('user_id', $poster_ids) . '
						AND user_posts < ' . $num_posts;
				$db->sql_query($sql);

				$sql = 'UPDATE ' . USERS_TABLE . '
					SET user_posts = user_posts - ' . $num_posts . '
					WHERE ' . $db->sql_in_set('user_id', $poster_ids) . '
						AND user_posts >= ' . $num_posts;
				$db->sql_query($sql);
			}
			else
			{
				$sql = 'UPDATE ' . USERS_TABLE . '
					SET user_posts = user_posts + ' . $num_posts . '
					WHERE ' . $db->sql_in_set('user_id', $poster_ids) . '
						AND user_posts >= ' . $num_posts;
				$db->sql_query($sql);
			}
		}

		$update_topic_postcount = true;

		// Sync the first/last topic information if needed
		if (!$is_starter && $is_latest)
		{
			// update_post_information can only update the last post info ...
			if ($topic_id)
			{
				update_post_information('topic', $topic_id, false);
			}
			if ($forum_id)
			{
				update_post_information('forum', $forum_id, false);
			}
		}
		else if ($is_starter && $topic_id)
		{
			if (!function_exists('sync'))
			{
				global $phpEx, $phpbb_root_path;
				include($phpbb_root_path . 'includes/functions_admin.' . $phpEx);
			}

			// ... so we need to use sync, if the first post is changed.
			// The forum is resynced recursive by sync() itself.
			sync('topic', 'topic_id', $topic_id, true);

			// sync recalculates the topic replies and forum posts by itself, so we don't do that.
			$update_topic_postcount = false;
		}

		// Update the topic's reply count and the forum's post count
		if ($update_topic_postcount)
		{
			$num_posts = 0;
			foreach ($postcount_visibility as $post_visibility => $visibility_posts)
			{
				// If we soft delete, we need to substract approved posts from the counters ...
				if ($post_visibility == ITEM_APPROVED && $visibility == ITEM_DELETED)
				{
					$num_posts += $visibility_posts;
				}
				// ... and when we approve/restore, all others.
				else if ($post_visibility != ITEM_APPROVED && $visibility == ITEM_APPROVED)
				{
					$num_posts += $visibility_posts;
				}
			}

			if ($num_posts)
			{
				$sql_num_posts = (($visibility == ITEM_DELETED) ? ' - ' : ' + ') . $num_posts;

				// Update the number for replies and posts
				$sql = 'UPDATE ' . TOPICS_TABLE . "
					SET topic_replies = topic_replies $sql_num_posts
					WHERE topic_id = $topic_id";
				$db->sql_query($sql);

				$sql = 'UPDATE ' . FORUMS_TABLE . "
					SET forum_posts = forum_posts $sql_num_posts
					WHERE forum_id = $forum_id";
				$db->sql_query($sql);
			}
		}

		return $data;
	}

	/**
	* Set topic visibility
	*
	* Allows approving (which is akin to undeleting/restore) or soft deleting an entire topic.
	* Calls set_post_visibility as needed.
	*
	* Note: By default, when a soft deleted topic is restored. Only posts that
	*		were approved at the time of soft deleting, are being restored.
	*		Same applies to soft deleting. Only approved posts will be marked
	*		as soft deleted.
	*		If you want to update all posts, use the force option.
	*
	* @param $visibility	int		Element of {ITEM_APPROVED, ITEM_DELETED}
	* @param $topic_id		mixed	Topic ID to act on
	* @param $forum_id		int		Forum where $topic_id is found
	* @param $user_id		int		User performing the action
	* @param $time			int		Timestamp when the action is performed
	* @param $reason		string	Reason why the visibilty was changed.
	* @param $force_update_all	bool	Force to update all posts within the topic
	* @return array		Changed topic data, empty array if an error occured.
	*/
	static public function set_topic_visibility($visibility, $topic_id, $forum_id, $user_id, $time, $reason, $force_update_all = false)
	{
		global $db;

		if (!in_array($visibility, array(ITEM_APPROVED, ITEM_DELETED)))
		{
			return array();
		}

		if (!$force_update_all)
		{
			$sql = 'SELECT topic_visibility, topic_delete_time
				FROM ' . TOPICS_TABLE . '
				WHERE topic_id = ' . (int) $topic_id;
			$result = $db->sql_query($sql);
			$original_topic_data = $db->sql_fetchrow($result);
			$db->sql_freeresult($result);

			if (!$original_topic_data)
			{
				// The topic does not exist...
				return array();
			}
		}

		// Note, we do not set a reason for the posts, just for the topic
		$data = array(
			'topic_visibility'		=> (int) $visibility,
			'topic_delete_user'		=> (int) $user_id,
			'topic_delete_time'		=> ((int) $time) ?: time(),
			'topic_delete_reason'	=> truncate_string($reason, 255, 255, false),
		);

		$sql = 'UPDATE ' . TOPICS_TABLE . '
			SET ' . $db->sql_build_array('UPDATE', $data) . '
			WHERE topic_id = ' . (int) $topic_id;
		$db->sql_query($sql);

		if (!$db->sql_affectedrows())
		{
			return array();
		}

		if (!$force_update_all && $original_topic_data['topic_delete_time'] && $original_topic_data['topic_visibility'] == ITEM_DELETED && $visibility == ITEM_APPROVED)
		{
			// If we're restoring a topic we only restore posts, that were soft deleted through the topic soft deletion.
			self::set_post_visibility($visibility, false, $topic_id, $forum_id, $user_id, $time, '', true, true, $original_topic_data['topic_visibility'], $original_topic_data['topic_delete_time']);
		}
		else if (!$force_update_all && $original_topic_data['topic_visibility'] == ITEM_APPROVED && $visibility == ITEM_DELETED)
		{
			// If we're soft deleting a topic we only approved posts are soft deleted.
			self::set_post_visibility($visibility, false, $topic_id, $forum_id, $user_id, $time, '', true, true, $original_topic_data['topic_visibility']);
		}
		else
		{
			self::set_post_visibility($visibility, false, $topic_id, $forum_id, $user_id, $time, '', true, true);
		}

		return $data;
	}

	/**
	* Add post to topic and forum statistics
	*
	* @param $data			array	Contains information from the topics table about given topic
	* @param $sql_data		array	Populated with the SQL changes, may be empty at call time
	* @return void
	*/
	static public function add_post_to_statistic($data, &$sql_data)
	{
		$sql_data[TOPICS_TABLE] = (($sql_data[TOPICS_TABLE]) ? $sql_data[TOPICS_TABLE] . ', ' : '') . 'topic_replies = topic_replies + 1';

		$sql_data[FORUMS_TABLE] = (($sql_data[FORUMS_TABLE]) ? $sql_data[FORUMS_TABLE] . ', ' : '') . 'forum_posts = forum_posts + 1';

		if ($data['post_postcount'])
		{
			$sql_data[USERS_TABLE] = (($sql_data[USERS_TABLE]) ? $sql_data[USERS_TABLE] . ', ' : '') . 'user_posts = user_posts + 1';
		}

		set_config_count('num_posts', 1, true);
	}

	/**
	* Remove post from topic and forum statistics
	*
	* @param $data			array	Contains information from the topics table about given topic
	* @param $sql_data		array	Populated with the SQL changes, may be empty at call time
	* @return void
	*/
	static public function remove_post_from_statistic($data, &$sql_data)
	{
		$sql_data[TOPICS_TABLE] = (($sql_data[TOPICS_TABLE]) ? $sql_data[TOPICS_TABLE] . ', ' : '') . 'topic_replies = topic_replies - 1';

		$sql_data[FORUMS_TABLE] = (($sql_data[FORUMS_TABLE]) ? $sql_data[FORUMS_TABLE] . ', ' : '') . 'forum_posts = forum_posts - 1';

		if ($data['post_postcount'])
		{
			$sql_data[USERS_TABLE] = (($sql_data[USERS_TABLE]) ? $sql_data[USERS_TABLE] . ', ' : '') . 'user_posts = user_posts - 1';
		}

		set_config_count('num_posts', -1, true);
	}

	/**
	* Remove topic from forum statistics
	*
	* @param $topic_id		int		The topic to act on
	* @param $forum_id		int		Forum where the topic is found
	* @param $topic_row		array	Contains information from the topic, may be empty at call time
	* @param $sql_data		array	Populated with the SQL changes, may be empty at call time
	* @return void
	*/
	static public function remove_topic_from_statistic($topic_id, $forum_id, &$topic_row, &$sql_data)
	{
		global $db;

		// Do we need to grab some topic informations?
		if (!sizeof($topic_row))
		{
			$sql = 'SELECT topic_type, topic_replies, topic_replies_real, topic_visibility
				FROM ' . TOPICS_TABLE . '
				WHERE topic_id = ' . $topic_id;
			$result = $db->sql_query($sql);
			$topic_row = $db->sql_fetchrow($result);
			$db->sql_freeresult($result);
		}

		// If this is an edited topic or the first post the topic gets completely disapproved later on...
		$sql_data[FORUMS_TABLE] = (($sql_data[FORUMS_TABLE]) ? $sql_data[FORUMS_TABLE] . ', ' : '') . 'forum_topics = forum_topics - 1';
		$sql_data[FORUMS_TABLE] .= ', forum_posts = forum_posts - ' . ($topic_row['topic_replies'] + 1);

		set_config_count('num_topics', -1, true);
		set_config_count('num_posts', ($topic_row['topic_replies'] + 1) * (-1), true);

		// Get user post count information
		$sql = 'SELECT poster_id, COUNT(post_id) AS num_posts
			FROM ' . POSTS_TABLE . '
			WHERE topic_id = ' . $topic_id . '
				AND post_postcount = 1
				AND post_visibility = ' . ITEM_APPROVED . '
			GROUP BY poster_id';
		$result = $db->sql_query($sql);

		$postcounts = array();
		while ($row = $db->sql_fetchrow($result))
		{
			$postcounts[(int) $row['num_posts']][] = (int) $row['poster_id'];
		}
		$db->sql_freeresult($result);

		// Decrement users post count
		foreach ($postcounts as $num_posts => $poster_ids)
		{
			$sql = 'UPDATE ' . USERS_TABLE . '
				SET user_posts = 0
				WHERE user_posts < ' . $num_posts . '
					AND ' . $db->sql_in_set('user_id', $poster_ids);
			$db->sql_query($sql);

			$sql = 'UPDATE ' . USERS_TABLE . '
				SET user_posts = user_posts - ' . $num_posts . '
				WHERE user_posts >= ' . $num_posts . '
					AND ' . $db->sql_in_set('user_id', $poster_ids);
			$db->sql_query($sql);
		}
	}
}
