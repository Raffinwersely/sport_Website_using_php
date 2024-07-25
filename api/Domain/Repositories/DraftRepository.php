<?php
namespace PhpDraft\Domain\Repositories;

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use PhpDraft\Domain\Entities\Draft;
use PhpDraft\Domain\Entities\Pick;

class DraftRepository {
  private $app;

  public function __construct(Application $app) {
    $this->app = $app;
  }

  public function GetPublicDrafts(Request $request, $password = '') {
    $draft_stmt = $this->app['db']->prepare("SELECT d.*, u.Name AS commish_name FROM draft d
      LEFT OUTER JOIN users u
      ON d.commish_id = u.id
      ORDER BY draft_create_time DESC");

    $draft_stmt->setFetchMode(\PDO::FETCH_CLASS, '\PhpDraft\Domain\Entities\Draft');

    $currentUser = $this->app['phpdraft.LoginUserService']->GetUserFromHeaderToken($request);

    if (!$draft_stmt->execute()) {
      throw new \Exception("Unable to load drafts.");
    }

    $drafts = array();

    while ($draft = $draft_stmt->fetch()) {
      $draft = $this->NormalizeDraftTimesAndStatuses($draft);
      $draft = $this->SetSecurityProperties($currentUser, $draft, $password);

      $drafts[] = $draft;
    }

    return $drafts;
  }

  public function GetPublicDraftsByCommish(Request $request, $commish_id, $password = '') {
    $commish_id = (int)$commish_id;

    $draftStatement = $this->app['db']->prepare("SELECT d.*, u.Name AS commish_name FROM draft d
    LEFT OUTER JOIN users u
    ON d.commish_id = u.id
    WHERE commish_id = ?
    ORDER BY draft_create_time DESC");

    $draftStatement->setFetchMode(\PDO::FETCH_CLASS, '\PhpDraft\Domain\Entities\Draft');
    $draftStatement->bindParam(1, $commish_id);

    if (!$draftStatement->execute()) {
      throw new \Exception("Unable to load drafts.");
    }

    $currentUser = $this->app['phpdraft.LoginUserService']->GetUserFromHeaderToken($request);

    $drafts = array();

    while ($draft = $draftStatement->fetch()) {
      $draft = $this->NormalizeDraftTimesAndStatuses($draft);
      $draft = $this->SetSecurityProperties($currentUser, $draft, $password);

      $drafts[] = $draft;
    }

    return $drafts;
  }

  //Note: this method is to be used by admin section only
  public function GetAllDraftsByCommish($commish_id) {
    $commish_id = (int)$commish_id;

    $draftStatement = $this->app['db']->prepare("SELECT d.*, u.Name AS commish_name FROM draft d
    LEFT OUTER JOIN users u
    ON d.commish_id = u.id
    WHERE commish_id = ?
    ORDER BY draft_create_time DESC");

    $draftStatement->setFetchMode(\PDO::FETCH_CLASS, '\PhpDraft\Domain\Entities\Draft');
    $draftStatement->bindParam(1, $commish_id);

    if (!$draftStatement->execute()) {
      throw new \Exception("Unable to load drafts.");
    }

    $drafts = array();

    while ($draft = $draftStatement->fetch()) {
      $draft->draft_create_time = $this->app['phpdraft.UtilityService']->ConvertTimeForClientDisplay($draft->draft_create_time);
      $draft->draft_start_time = $this->app['phpdraft.UtilityService']->ConvertTimeForClientDisplay($draft->draft_start_time);
      $draft->draft_end_time = $this->app['phpdraft.UtilityService']->ConvertTimeForClientDisplay($draft->draft_end_time);

      $drafts[] = $draft;
    }

    return $drafts;
  }

  //Note: this method is to be used by admin section only
  public function GetAllCompletedDrafts() {
    $draftStatement = $this->app['db']->prepare("SELECT d.*, u.Name AS commish_name FROM draft d
      LEFT OUTER JOIN users u
      ON d.commish_id = u.id
      WHERE d.draft_status = 'complete'
      ORDER BY draft_create_time DESC");

    $draftStatement->setFetchMode(\PDO::FETCH_CLASS, '\PhpDraft\Domain\Entities\Draft');

    if (!$draftStatement->execute()) {
      throw new \Exception("Unable to load drafts.");
    }

    $drafts = array();

    while ($draft = $draftStatement->fetch()) {
      $draft = $this->NormalizeDraftTimesAndStatuses($draft);
      //Skipping security call here as we have not passed a request in (thus no currentUser) and this is only called by admin

      $drafts[] = $draft;
    }

    return $drafts;
  }

  public function GetPublicDraft(Request $request, $id, $getDraftData = false, $password = '') {
    $cachedDraft = $this->GetCachedDraft($id);

    if ($cachedDraft != null) {
      $draft = $cachedDraft;
    } else {
      $draft = $this->FetchPublicDraftById($id);

      $this->SetCachedDraft($draft);
    }

    $currentUser = $this->app['phpdraft.LoginUserService']->GetUserFromHeaderToken($request);

    $draft = $this->NormalizeDraftTimesAndStatuses($draft);
    $draft = $this->SetSecurityProperties($currentUser, $draft, $password);

    if ($getDraftData) {
      $draft->sports = $this->app['phpdraft.DraftDataRepository']->GetSports();
      $draft->styles = $this->app['phpdraft.DraftDataRepository']->GetStyles();
      $draft->statuses = $this->app['phpdraft.DraftDataRepository']->GetStatuses();
      $draft->teams = $this->app['phpdraft.DraftDataRepository']->GetTeams($draft->draft_sport);
      $draft->historical_teams = $this->app['phpdraft.DraftDataRepository']->GetHistoricalTeams($draft->draft_sport);
      $draft->positions = $this->app['phpdraft.DraftDataRepository']->GetPositions($draft->draft_sport);

      if ($draft->using_depth_charts) {
        $draft->depthChartPositions = $this->app['phpdraft.DepthChartPositionRepository']->LoadAll($draft->draft_id);
      }
    }

    return $draft;
  }

  /*
  * This method is only to be used internally or when the user has been verified as owner of the draft (or is admin)
  * (in other words, don't call this then return the result as JSON!)
  */
  public function Load($id, $bustCache = false) {
    $cachedDraft = $this->GetCachedDraft($id);

    if ($bustCache || $cachedDraft == null) {
      $draft = $this->FetchPublicDraftById($id);

      if ($bustCache) {
        $this->UnsetCachedDraft($draft->draft_id);
      }

      $this->SetCachedDraft($draft);
    } else {
      $draft = $cachedDraft;
    }

    $draft->draft_rounds = (int)$draft->draft_rounds;

    return $draft;
  }

  public function Create(Draft $draft) {
    $insert_stmt = $this->app['db']->prepare("INSERT INTO draft
      (draft_id, commish_id, draft_create_time, draft_name, draft_sport, draft_status, draft_style, draft_rounds, draft_password, using_depth_charts)
      VALUES
      (NULL, ?, UTC_TIMESTAMP(), ?, ?, ?, ?, ?, ?, ?)");

    $insert_stmt->bindParam(1, $draft->commish_id);
    $insert_stmt->bindParam(2, $draft->draft_name);
    $insert_stmt->bindParam(3, $draft->draft_sport);
    $insert_stmt->bindParam(4, $draft->draft_status);
    $insert_stmt->bindParam(5, $draft->draft_style);
    $insert_stmt->bindParam(6, $draft->draft_rounds);
    $insert_stmt->bindParam(7, $draft->draft_password);
    $insert_stmt->bindParam(8, $draft->using_depth_charts);

    if (!$insert_stmt->execute()) {
      throw new \Exception("Unable to create draft.");
    }

    $draft = $this->Load((int)$this->app['db']->lastInsertId(), true);

    return $draft;
  }

  //Excluded properties in update:
  //draft_start_time/draft_end_time - updated in separate operations at start/end of draft
  //draft_current_round/draft_current_pick - updated when new picks are made
  //draft_counter - call IncrementDraftCounter instead - this call's made a lot independently of other properties.
  //draft_status - separate API call to update the draft status
  public function Update(Draft $draft) {
    $update_stmt = $this->app['db']->prepare("UPDATE draft
      SET commish_id = ?, draft_name = ?, draft_sport = ?,
      draft_style = ?, draft_password = ?, draft_rounds = ?,
      using_depth_charts = ?
      WHERE draft_id = ?");

    $draft->using_depth_charts = $draft->using_depth_charts;

    $update_stmt->bindParam(1, $draft->commish_id);
    $update_stmt->bindParam(2, $draft->draft_name);
    $update_stmt->bindParam(3, $draft->draft_sport);
    $update_stmt->bindParam(4, $draft->draft_style);
    $update_stmt->bindParam(5, $draft->draft_password);
    $update_stmt->bindParam(6, $draft->draft_rounds);
    $update_stmt->bindParam(7, $draft->using_depth_charts);
    $update_stmt->bindParam(8, $draft->draft_id);

    if (!$update_stmt->execute()) {
      throw new \Exception("Unable to update draft.");
    }

    $this->ResetDraftCache($draft->draft_id);

    return $draft;
  }

  public function UpdateStatus(Draft $draft) {
    $status_stmt = $this->app['db']->prepare("UPDATE draft
      SET draft_status = ? WHERE draft_id = ?");

    $status_stmt->bindParam(1, $draft->draft_status);
    $status_stmt->bindParam(2, $draft->draft_id);

    if (!$status_stmt->execute()) {
      throw new \Exception("Unable to update draft status.");
    }

    $this->ResetDraftCache($draft->draft_id);

    return $draft;
  }

  public function UpdateStatsTimestamp(Draft $draft) {
    $status_stmt = $this->app['db']->prepare("UPDATE draft
      SET draft_stats_generated = UTC_TIMESTAMP() WHERE draft_id = ?");

    $status_stmt->bindParam(1, $draft->draft_id);

    if (!$status_stmt->execute()) {
      throw new \Exception("Unable to update draft's stats timestamp.");
    }

    $this->ResetDraftCache($draft->draft_id);

    return $draft;
  }

  public function IncrementDraftCounter(Draft $draft) {
    $incrementedCounter = (int)$draft->draft_counter + 1;

    $increment_stmt = $this->app['db']->prepare("UPDATE draft
      SET draft_counter = ? WHERE draft_id = ?");

    $increment_stmt->bindParam(1, $incrementedCounter);
    $increment_stmt->bindParam(2, $draft->draft_id);

    if (!$increment_stmt->execute()) {
      throw new \Exception("Unable to increment draft counter.");
    }

    $this->ResetDraftCache($draft->draft_id);

    return $incrementedCounter;
  }

  //$next_pick can't be type-hinted - can be null
  public function MoveDraftForward(Draft $draft, $next_pick) {
    if ($next_pick !== null) {
      $draft->draft_current_pick = (int)$next_pick->player_pick;
      $draft->draft_current_round = (int)$next_pick->player_round;

      $stmt = $this->app['db']->prepare("UPDATE draft SET draft_current_pick = ?, draft_current_round = ? WHERE draft_id = ?");
      $stmt->bindParam(1, $draft->draft_current_pick);
      $stmt->bindParam(2, $draft->draft_current_round);
      $stmt->bindParam(3, $draft->draft_id);

      if (!$stmt->execute()) {
        throw new \Exception("Unable to move draft forward.");
      }
    } else {
      $draft->draft_status = 'complete';
      $stmt = $this->app['db']->prepare("UPDATE draft SET draft_status = ?, draft_end_time = UTC_TIMESTAMP() WHERE draft_id = ?");
      $stmt->bindParam(1, $draft->draft_status);
      $stmt->bindParam(2, $draft->draft_id);

      if (!$stmt->execute()) {
        throw new \Exception("Unable to move draft forward.");
      }
    }

    $this->ResetDraftCache($draft->draft_id);

    return $draft;
  }

  //Used when we move a draft from "undrafted" to "in_progress":
  //Resets the draft counter
  //Sets the current pick and round to 1
  //Sets the draft start time to UTC now, nulls out end time
  public function SetDraftInProgress(Draft $draft) {
    $reset_stmt = $this->app['db']->prepare("UPDATE draft
      SET draft_counter = 0, draft_current_pick = 1, draft_current_round = 1,
      draft_start_time = UTC_TIMESTAMP(), draft_end_time = NULL
      WHERE draft_id = ?");

    $reset_stmt->bindParam(1, $draft->draft_id);

    if (!$reset_stmt->execute()) {
      throw new \Exception("Unable to set draft to in progress.");
    }

    $this->ResetDraftCache($draft->draft_id);

    return 0;
  }

  public function NameIsUnique($name, $id = null) {
    if (!empty($id)) {
      $name_stmt = $this->app['db']->prepare("SELECT draft_name FROM draft WHERE draft_name LIKE ? AND draft_id <> ?");
      $name_stmt->bindParam(1, $name);
      $name_stmt->bindParam(2, $id);
    } else {
      $name_stmt = $this->app['db']->prepare("SELECT draft_name FROM draft WHERE draft_name LIKE ?");
      $name_stmt->bindParam(1, $name);
    }

    if (!$name_stmt->execute()) {
      throw new \Exception("Draft name '%s' is invalid", $name);
    }

    return $name_stmt->rowCount() == 0;
  }

  public function DeleteDraft($draft_id) {
    $delete_stmt = $this->app['db']->prepare("DELETE FROM draft WHERE draft_id = ?");
    $delete_stmt->bindParam(1, $draft_id);

    if (!$delete_stmt->execute()) {
      throw new \Exception("Unable to delete draft $draft_id.");
    }

    $this->UnsetCachedDraft($draft_id);

    return;
  }

  private function ResetDraftCache($draft_id) {
    $draft = $this->Load($draft_id, true);
  }

  private function SetCachedDraft(Draft $draft) {
    $this->app['phpdraft.DatabaseCacheService']->SetCachedItem("draft$draft->draft_id", $draft);
  }

  private function GetCachedDraft($draft_id) {
    return $this->app['phpdraft.DatabaseCacheService']->GetCachedItem("draft$draft_id");
  }

  private function UnsetCachedDraft($draft_id) {
    $this->app['phpdraft.DatabaseCacheService']->DeleteCachedItem("draft$draft_id");
  }

  private function ProtectPrivateDraft(Draft $draft) {
    $draft->draft_sport = '';
    $draft->setting_up = '';
    $draft->in_progress = '';
    $draft->complete = '';
    $draft->draft_style = '';
    $draft->draft_rounds = '';
    $draft->draft_counter = '';
    $draft->draft_start_time = null;
    $draft->draft_end_time = null;
    $draft->draft_current_pick = '';
    $draft->draft_current_round = '';
    $draft->draft_create_time = '';
    $draft->draft_stats_generated = '';
    $draft->nfl_extended = null;
    $draft->sports = null;
    $draft->styles = null;
    $draft->statuses = null;
    $draft->teams = null;
    $draft->positions = null;
    $draft->using_depth_charts = null;
    $draft->depthChartPositions = null;

    return $draft;
  }

  private function FetchPublicDraftById($id) {
    $draft = new Draft();

    $draft_stmt = $this->app['db']->prepare("SELECT d.*, u.Name AS commish_name FROM draft d
      LEFT OUTER JOIN users u
      ON d.commish_id = u.id
      WHERE d.draft_id = ? LIMIT 1");

    $draft_stmt->setFetchMode(\PDO::FETCH_INTO, $draft);

    $draft_stmt->bindParam(1, $id, \PDO::PARAM_INT);

    if (!$draft_stmt->execute() || !$draft_stmt->fetch()) {
      throw new \Exception("Unable to load draft");
    }

    $draft->using_depth_charts = $draft->using_depth_charts == 1;

    return $draft;
  }

  private function NormalizeDraftTimesAndStatuses($draft) {
    $draft->setting_up = $this->app['phpdraft.DraftService']->DraftSettingUp($draft);
    $draft->in_progress = $this->app['phpdraft.DraftService']->DraftInProgress($draft);
    $draft->complete = $this->app['phpdraft.DraftService']->DraftComplete($draft);

    $draft->display_status = $this->app['phpdraft.DraftService']->GetDraftStatusDisplay($draft);

    $draft->draft_create_time = $this->app['phpdraft.UtilityService']->ConvertTimeForClientDisplay($draft->draft_create_time);
    $draft->draft_start_time = $this->app['phpdraft.UtilityService']->ConvertTimeForClientDisplay($draft->draft_start_time);
    $draft->draft_end_time = $this->app['phpdraft.UtilityService']->ConvertTimeForClientDisplay($draft->draft_end_time);

    return $draft;
  }

  private function SetSecurityProperties($currentUser, $draft, $password) {
    $currentUserOwnsIt = !empty($currentUser) && $draft->commish_id == $currentUser->id;
    $currentUserIsAdmin = !empty($currentUser) && $this->app['phpdraft.LoginUserService']->CurrentUserIsAdmin($currentUser);

    $draft->draft_visible = empty($draft->draft_password);
    $draft->commish_editable = $currentUserOwnsIt || $currentUserIsAdmin;
    $draft->is_locked = false;

    if (!$draft->commish_editable && !$draft->draft_visible && $password != $draft->draft_password) {
      $draft->is_locked = true;
      $draft->draft_status = 'locked';
      $draft->display_status = 'Locked';
      $draft = $this->ProtectPrivateDraft($draft);
    }

    unset($draft->draft_password);

    return $draft;
  }
}
