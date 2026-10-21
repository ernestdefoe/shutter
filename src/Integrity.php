<?php

namespace ErnestDefoe\Shutter;

use ErnestDefoe\Shutter\Model\Contest;
use Illuminate\Database\ConnectionInterface;

/**
 * Basic vote-ring detection: votes worth a second look, and why.
 *
 * Nothing here removes a vote. It flags, and staff decide — a flatmate voting
 * for a flatmate is a "same network" flag and may be perfectly fair.
 *
 * - same_network: the voter's network is the entrant's (a sock puppet at
 *   home, or a household).
 * - shared_network: three or more voters for one entry from one network.
 * - new_account: the account was made after the contest began.
 * - no_posts: the voter has never posted (only shown beside another sign).
 * - mutual: two entrants who voted for each other's photos.
 *
 * Three queries for the whole contest, whatever its size.
 */
class Integrity
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    /** @return array{entries: list<array<string, mixed>>, flagged: int, total: int} */
    public function review(Contest $contest): array
    {
        $votes = $this->db->table('shutter_votes as v')
            ->join('users as u', 'u.id', '=', 'v.user_id')
            ->where('v.contest_id', $contest->id)
            ->orderBy('v.id')
            ->get(['v.id', 'v.entry_id', 'v.user_id', 'v.ip_hash', 'v.voided_at', 'v.created_at', 'u.username', 'u.joined_at', 'u.comment_count']);

        $entries = $this->db->table('shutter_entries as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->where('e.contest_id', $contest->id)
            ->get(['e.id', 'e.title', 'e.user_id', 'e.ip_hash', 'u.username'])
            ->keyBy('id');

        $began = $contest->opens_at ?? $contest->created_at;

        // Who voted for whose photos: entrant → set of entrants they backed.
        $backed = [];
        foreach ($votes as $vote) {
            $owner = $entries->get($vote->entry_id)->user_id ?? null;
            if ($owner && ! $vote->voided_at) {
                $backed[(int) $vote->user_id][(int) $owner] = true;
            }
        }

        // Voters per (entry, network).
        $crowd = [];
        foreach ($votes as $vote) {
            if ($vote->ip_hash && ! $vote->voided_at) {
                $crowd[$vote->entry_id.'|'.$vote->ip_hash] = ($crowd[$vote->entry_id.'|'.$vote->ip_hash] ?? 0) + 1;
            }
        }

        $byEntry = [];
        $flagged = 0;

        foreach ($votes as $vote) {
            $entry = $entries->get($vote->entry_id);
            $owner = (int) ($entry->user_id ?? 0);
            $reasons = [];

            if ($vote->ip_hash && $entry && $vote->ip_hash === $entry->ip_hash) {
                $reasons[] = 'same_network';
            }
            if ($vote->ip_hash && ($crowd[$vote->entry_id.'|'.$vote->ip_hash] ?? 0) >= 3) {
                $reasons[] = 'shared_network';
            }
            if ($began && $vote->joined_at && strtotime((string) $vote->joined_at) > $began->getTimestamp()) {
                $reasons[] = 'new_account';
            }
            if ($owner && isset($backed[$owner][(int) $vote->user_id])) {
                $reasons[] = 'mutual';
            }
            // Plenty of honest members never post, so on its own this is
            // noise; beside another sign it is worth saying.
            if ($reasons && (int) $vote->comment_count === 0) {
                $reasons[] = 'no_posts';
            }

            $id = (int) $vote->entry_id;
            $byEntry[$id] ??= ['id' => $id, 'title' => $entry->title ?? '', 'entrant' => $entry->username ?? null, 'votes' => 0, 'flagged' => []];

            if (! $vote->voided_at) {
                $byEntry[$id]['votes']++;
            }

            if ($reasons || $vote->voided_at) {
                $flagged += $reasons && ! $vote->voided_at ? 1 : 0;
                $byEntry[$id]['flagged'][] = [
                    'voteId' => (int) $vote->id,
                    'voter' => ['id' => (int) $vote->user_id, 'username' => $vote->username],
                    'reasons' => $reasons,
                    'voided' => $vote->voided_at !== null,
                ];
            }
        }

        $list = array_values(array_filter($byEntry, fn ($e) => $e['flagged'] !== []));
        usort($list, fn ($a, $b) => count($b['flagged']) <=> count($a['flagged']));

        return ['entries' => $list, 'flagged' => $flagged, 'total' => count($votes)];
    }
}
