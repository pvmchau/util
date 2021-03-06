<?php

namespace go1\util\enrolment;

use Doctrine\DBAL\Connection;
use go1\util\DB;
use go1\util\edge\EdgeHelper;
use go1\util\edge\EdgeTypes;
use go1\util\lo\LoTypes;
use PDO;
use stdClass;

/**
 * @TODO We're going to load & attach edges into enrolment.
 *
 *  - assessor
 *  - expiration
 *  - ...
 *
 * Format will like:
 *  $enrolment->edges[edge-type][] = edge
 */
class EnrolmentHelper
{
    public static function enrolmentId(Connection $db, int $loId, int $profileId)
    {
        return $db->fetchColumn('SElECT id FROM gc_enrolment WHERE lo_id = ? AND profile_id = ?', [$loId, $profileId]);
    }

    public static function load(Connection $db, int $id, bool $loadEdges = false)
    {
        return ($enrolments = static::loadMultiple($db, [$id])) ? $enrolments[0] : false;
    }

    public static function loadMultiple(Connection $db, array $ids, bool $loadEdges = false): array
    {
        return $db
            ->executeQuery('SELECT * FROM gc_enrolment WHERE id IN (?)', [$ids], [Connection::PARAM_INT_ARRAY])
            ->fetchAll(DB::OBJ);
    }

    public static function loadByParentLo(Connection $db, int $parentLoId, bool $loadEdges = false): array
    {
        return $db
            ->executeQuery('SELECT * FROM gc_enrolment WHERE parent_lo_id = ?', [$parentLoId])
            ->fetchAll(DB::OBJ);
    }

    public static function loadByLo(Connection $db, int $loId, bool $loadEdges = false): array
    {
        return $db
            ->executeQuery('SELECT * FROM gc_enrolment WHERE lo_id = ?', [$loId])
            ->fetchAll(DB::OBJ);
    }

    public static function loadByLoAndProfileId(Connection $db, int $loId, int $profileId, int $parentLoId = null)
    {
        $q = $db
            ->createQueryBuilder()
            ->select('*')
            ->from('gc_enrolment')
            ->where('lo_id = :lo_id')->setParameter(':lo_id', $loId)
            ->andWhere('profile_id = :profile_id')->setParameter(':profile_id', $profileId);

        $parentLoId && $q->andWhere('parent_lo_id = :parent_lo_id')->setParameter(':parent_lo_id', $parentLoId);

        return $q->execute()->fetch(DB::OBJ);
    }

    public static function becomeCompleted(stdClass $enrolment, stdClass $original, bool $passAware = true): bool
    {
        $status = $enrolment->status;
        $previousStatus = $original->status;

        if ($status != $previousStatus) {
            if (EnrolmentStatuses::COMPLETED === $status) {
                return $passAware ? (1 == $enrolment->pass) : true;
            }
        }

        return false;
    }

    # Check that all dependencies are completed.
    # Only return true if # of completion = # of dependencies
    public static function dependenciesCompleted(Connection $db, stdClass $enrolment, bool $passAware = true): bool
    {
        $moduleId = $enrolment->lo_id;
        $dependencyIds = 'SELECT target_id FROM gc_ro WHERE type = ? AND source_id = ?';
        $dependencyIds = $db->executeQuery($dependencyIds, [EdgeTypes::HAS_MODULE_DEPENDENCY, $moduleId])->fetchAll(PDO::FETCH_COLUMN);
        if (!$dependencyIds) {
            return false; // If there's no dependencies -> input is wrong -> return false
        }

        if ($passAware) {
            $completion = 'SELECT COUNT(*) FROM gc_enrolment WHERE id IN (?) AND status = ? AND pass = 1';
            $completion = $db->fetchColumn($completion, [$dependencyIds, EnrolmentStatuses::COMPLETED], 0, [DB::INTEGERS, DB::STRING]);
        }
        else {
            $completion = 'SELECT COUNT(*) FROM gc_enrolment WHERE id IN (?) AND status = ?';
            $completion = $db->fetchColumn($completion, [$dependencyIds], 0, [DB::INTEGERS]);
        }

        return $completion == count($dependencyIds);
    }

    public static function assessorIds(Connection $db, int $enrolmentId): array
    {
        return EdgeHelper
            ::select('source_id')
            ->get($db, [], [$enrolmentId], [EdgeTypes::HAS_TUTOR_ENROLMENT_EDGE], PDO::FETCH_COLUMN);
    }

    public static function findParentEnrolment(Connection $db, stdClass $enrolment, $parentLoType = LoTypes::COURSE)
    {
        $loadLo = function ($loId) use ($db) {
            return $db->executeQuery('SELECT id, type FROM gc_lo WHERE id = ?', [$loId])->fetch(DB::OBJ);
        };

        $parentQuery = function (stdClass $lo, stdClass $enrolment) use ($db, $loadLo) {
            $parentLoId = $enrolment->parent_lo_id ?: false;
            if (empty($parentLoId)) {
                $roTypes = [EdgeTypes::HAS_LP_ITEM, EdgeTypes::HAS_MODULE, EdgeTypes::HAS_ELECTIVE_LO, EdgeTypes::HAS_LI, EdgeTypes::HAS_ELECTIVE_LI];
                $query = $db->executeQuery('SELECT source_id FROM gc_ro WHERE type IN (?) AND target_id = ?', [$roTypes, $lo->id], [DB::INTEGERS, DB::INTEGER]);
                $parentLoId = $query->fetchColumn();
            }

            return [
                $parentLo = $parentLoId ? $loadLo($parentLoId) : false,
                $parentEnrolment = $parentLo ? EnrolmentHelper::loadByLoAndProfileId($db, $parentLo->id, $enrolment->profile_id) : false,
            ];
        };
        $lo = $loadLo($enrolment->lo_id);
        list($parentLo, $parentEnrolment) = $parentQuery($lo, $enrolment);
        while ($parentLo && $parentEnrolment && ($parentLo->type != $parentLoType)) {
            list($parentLo, $parentEnrolment) = $parentQuery($parentLo, $parentEnrolment);
        }

        return $parentLo && ($parentLo->type == $parentLoType) ? $parentEnrolment : false;
    }
}
