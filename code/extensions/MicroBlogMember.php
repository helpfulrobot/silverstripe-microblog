<?php

/**
 *
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class MicroBlogMember extends DataExtension {
	const FRIENDS = 'Friends';
	const FOLLOWERS = 'Followers';
	const BALANCE_THRESHOLD = -20;
	
	private static $microblog_group_name = 'Microblog user groups';
	
	private static $db = array(
		'Username'					=> 'Varchar',
		'PostPermission'			=> 'Varchar',
		'VotesToGive'				=> 'Int',
		'Balance'					=> 'Int',
		'Up'						=> 'Int',
		'Down'						=> 'Int',
		'LastPostView'				=> 'SS_DateTime',
	);

	private static $has_one = array(
		'UploadFolder'			=> 'Folder',

		// where all our friends get added 
		'FriendsGroup'			=> 'Group',
		'FollowersGroup'		=> 'Group',
		
		'MyPermSource'			=> 'PermissionParent',
	);
	
	private static $defaults = array(
		'PostPermission'		=> 'Public'
	);

	private static $dependencies = array(
		'microBlogService'		=> '%$MicroBlogService',
		'permissionService'		=> '%$PermissionService',
		'transactionManager'	=> '%$TransactionManager',
	);
	
	static $permission_options = array(
		'Hidden',
		'Friends only',
		'Friends and followers',
		'Public'
	);
	
	static $summary_fields = array(
		'Username',
		'Up',
		'Down',
		'Balance',
	);
	
	/**
	 * @var MicroBlogService
	 */
	public $microBlogService;
	
	/**
	 * @var PermissionService
	 */
	public $permissionService;
	
	/**
	 * @var TransactionManager 
	 */
	public $transactionManager;
	
	private $unreadPosts;
	
	/**
	 * Gets the latest posts that _this_ member can view
	 */
	public function getUnreadPosts($tags = '') {
		// this little cache bit has absolutely no effect because SS will re-create (And requery) the database
		// on EVERY call to currentMember
		if (!$this->unreadPosts) {
			$filter = array(
				'Created:GreaterThan'		=> $this->owner->LastPostView, 
			);
			
			if (strlen($tags) && preg_match('/[a-z0-9_,-]/i', $tags)) {
				$tags = explode(',', $tags);
				$filter['Tags.Title'] = $tags;
			}
			
			$this->unreadPosts = $this->microBlogService->globalFeed($filter, $orderBy = 'ID DESC', $since = null, $number = 20, $markViewed = false);
		}
		
		return $this->unreadPosts;
	}
	
	public function updateCMSFields(\FieldList $fields) {
		$fields->removeByName('UploadFolder');
	}
	
	public function onBeforeWrite() {

		if (!strlen($this->owner->Username)) {
			
			if ($this->owner->FirstName == 'Default Admin') {
				$this->owner->Email = 'defaultadmin';
			}

			if ($this->owner->Email) {
				$name = strpos($this->owner->Email, '@') !== false ? substr($this->owner->Email, 0, strpos($this->owner->Email, '@')) : $this->owner->Email;
				$name = preg_replace('/[^a-zA-Z]/i', '', $name);
				$this->owner->Username = $name;
			} else {
				throw new ValidationException("Cannot create user without a username");
			}
		}

		// @TODO Make this allow various utf8 characters
		if (!preg_match('/^[a-z0-9_\. -]+$/i', $this->owner->Username)) {
			throw new ValidationException('Username must only contain word characters');
		}

		if (!$this->owner->ID) {
			// find an existing user with this username and bail if so
			$existing = DataList::create('Member')->filter(array('Username' => $this->owner->Username))->first();
			if ($existing && $existing->ID) {
				throw new ValidationException("Username already exists");
			}
		}

		parent::onBeforeWrite();

		if ($this->owner->OwnerID != $this->owner->ID) {
			$this->owner->OwnerID = $this->owner->ID;
		}

		if (!$this->owner->ID) {
			$this->owner->InheritPerms = true;
		}

		$changed = $this->owner->isChanged('Username') || $this->owner->isChanged('FirstName') || $this->owner->isChanged('Surname') || $this->owner->isChanged('Email');

		if ($this->owner->ID && !$this->owner->ProfileID) {
			$profile = \SilverStripeAustralia\Profiles\MemberProfile::create();
			$this->syncProfile($profile);
			$this->owner->ProfileID = $profile->ID;
		} else if ($this->owner->ProfileID && $changed) {
			$this->syncProfile($this->owner->Profile());
		}
		
		$this->getGroupFor(self::FRIENDS);
		$this->getGroupFor(self::FOLLOWERS);
		
		$this->owner->Balance = $this->owner->Up - $this->owner->Down;
		
		$this->memberFolder();
	}
	
	public function onAfterWrite() {
		parent::onAfterWrite();
	}

	protected function syncProfile($profile) {
		$profile->Username = $this->owner->Username;
		$profile->Email = $this->owner->Email;
		$profile->MemberID = $this->owner->ID;
		$profile->Votes = $this->owner->VotesToGive;
		$profile->write();
	}

	public function publicProfile() {
		if ($this->owner->ID && !$this->owner->ProfileID) {
			$profile = \SilverStripeAustralia\Profiles\MemberProfile::create();
			$this->syncProfile($profile);
			$this->owner->ProfileID = $profile->ID;
		}
		return $this->owner->Profile();
	}

	public function canView() {
		return true;
	}
	
	public function canVote() {
		return $this->VotesToGive > 0;
	}
	
	/**
	 * Retrieve the container permission source for all this user's posts 
	 */
	public function postPermissionSource() {
		if ($this->owner->MyPermSourceID) {
			return $this->owner->MyPermSource();
		}

		$source = new PermissionParent();
		$source->Title = 'Posts for ' . $this->owner->getTitle();
		
		$owner = $this->owner;
		
		$this->transactionManager->run(function () use($source, $owner) {
			$source->write();
			$owner->MyPermSourceID = $source->ID;
			$owner->write();
		}, $owner);
		
		return $source;
	}
	
	public function clearCurrentPermissions() {
		$source = $this->postPermissionSource();
		
		$this->permissionService->removePermissions($source, 'View', $this->getGroupFor(self::FOLLOWERS));
		$this->permissionService->removePermissions($source, 'View', $this->getGroupFor(self::FRIENDS));

		$source->InheritPerms = false;
		$source->PublicAccess = false;
	}
	
	/**
	 * set permissions for this user's posts 
	 */
	public function updatePostPermissions() {
		$set = $this->owner->PostPermission;
		$source = $this->postPermissionSource();
		
		switch ($set) {
			case 'Hidden': {
				$this->permissionService->removePermissions($source, 'View', $this->getGroupFor(self::FOLLOWERS));
				$this->permissionService->removePermissions($source, 'View', $this->getGroupFor(self::FRIENDS));

				$source->InheritPerms = false;
				$source->PublicAccess = false;
				break;
			}
			case 'Friends only': {
				$source->InheritPerms = false;
				$source->PublicAccess = false;
				
				$this->permissionService->removePermissions($source, 'View', $this->getGroupFor(self::FOLLOWERS));
				$this->permissionService->grant($source, 'View', $this->getGroupFor(self::FRIENDS));
				break;
			}
			
			case 'Friends and followers': {
				$source->InheritPerms = false;
				$source->PublicAccess = false;

				$this->permissionService->grant($source, 'View', $this->getGroupFor(self::FOLLOWERS));
				$this->permissionService->grant($source, 'View', $this->getGroupFor(self::FRIENDS));
				break;
			}
			
			case 'Public': {
				$source->PublicAccess = true;
				break;
			}
		}
		
		$source->write();
	}
	
	public function memberFolder() {
		if (!$this->owner->UploadFolderID || !$this->owner->UploadFolder()->exists()) {
			
			$multisiteFolder = null;
			if (class_exists('Multisites')) {
				$site = Multisites::inst()->getActiveSite();
				if ($site) {
					$multisiteFolder = $site->Folder();
				}
			}
			
			// get the folder for this user
			$name = md5($this->owner->ID . time());
			$path = 'user-files/' . $name;
			if ($multisiteFolder) {
				$path = $multisiteFolder->Name . '/' . $path;
			}

			$this->owner->UploadFolderID = Folder::find_or_make($path)->ID;
		}
		return $this->owner->UploadFolder();
	}

	/**
	 * gets the group that this user's friends belong to 
	 */
	public function getGroupFor($type) {
		$groupType = $type.'Group';
		$groupTypeID = $type.'GroupID';
		
		if ($this->owner->$groupTypeID) {
			return $this->owner->$groupType();
		}

		$title = $this->owner->Email . ' ' . $type;
		$group = DataList::create('Group')->filter(array('Title' => $title))->first();
		if ($group && $group->exists()) {
			$this->owner->$groupTypeID = $group->ID;
			return $group;
		} else {
			$group = $this->transactionManager->runAsAdmin(function () use ($title) {
				$grouping = Group::get()->filter('Title', Config::inst()->get('MicroBlogMember', 'microblog_group_name'))->first();
				if (!$grouping) {
					$grouping = Group::create(array('Title', Config::inst()->get('MicroBlogMember', 'microblog_group_name')));
					$grouping->write();
				}

				$group = Group::create();
				$group->Title = $title;
				$group->ParentID = $grouping->ID;
				$group->write();
				return $group;
			});
			if ($group) {
				$this->owner->$groupTypeID = $group->ID;
			}
			return $group;
		}
	}
	
	public function toFilteredMap() {
		$allowed = array(
			'FirstName',
			'Surname',
			'Email',
		);

		$map = array();
		foreach ($allowed as $prop) {
			$map[$prop] = $this->owner->$prop;
		}
		
		return $map;
	}
	
	public function Friends() {
		return $this->microBlogService->friendsList($this->owner);
	}
	
	public function Link() {
		return 'timeline/user/' . $this->owner->ID;
	}
}