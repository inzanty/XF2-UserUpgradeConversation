<?php

namespace INZ\UserUpgradeConversation\Option;

use XF\Option\AbstractOption;
use XF\Util\Arr;

class Conversation extends AbstractOption
{
    public static function verifyOption(array &$values, \XF\Entity\Option $option)
    {
        if ($option->isInsert())
        {
            return true;
        }

        if (!empty($values['conversationEnabled']))
        {
            $participants = Arr::stringToArray($values['conversationParticipants'], '#\s*,\s*#');
            if (!$participants)
            {
                $option->error(\XF::phrase('please_enter_at_least_one_valid_recipient'), $option->option_id);
                return false;
            }

            $starterName = array_shift($participants);
            $starter = \XF::em()->findOne('XF:User', ['username' => $starterName]);
            if (!$starter)
            {
                $option->error(\XF::phrase('the_following_recipients_could_not_be_found_x', ['names' => $starterName]), $option->option_id);
                return false;
            }

            /** @var \XF\Repository\User $userRepo */
            $userRepo = \XF::repository('XF:User');
            $users = $userRepo->getUsersByNames($participants, $notFound, [], false);
            if ($notFound)
            {
                $option->error(\XF::phrase('the_following_recipients_could_not_be_found_x', ['names' => implode(', ', $notFound)]), $option->option_id);
                return false;
            }

            $values['conversationParticipants'] = $users->keys();
            array_unshift($values['conversationParticipants'], $starter->user_id);

            if (!$values['conversationTitle'] && !$values['conversationBody'])
            {
                $option->error(\XF::phrase('inz_user_upgrade_conversation_please_enter_valid_conversation_contents'), $option->option_id);
                return false;
            }
        }
        else
        {
            unset($values['conversationParticipants']);
        }

        return true;
    }
}