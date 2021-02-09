<?php

namespace INZ\UserUpgradeConversation\XF\Service\User;

use XF\Entity\ConversationMaster;
use XF\Entity\User;

class Upgrade extends XFCP_Upgrade
{
    /**
     * @var ConversationMaster
     */
    protected $sendConversation;

    /**
     * @return ConversationMaster
     */
    public function getSendConversation()
    {
        return $this->sendConversation;
    }

    /**
     * @return false|\XF\Entity\UserUpgradeActive
     * @throws \XF\PrintableException
     */
    public function upgrade()
    {
        $reply = parent::upgrade();
        $options = \XF::options()->inzUserUpgradeConversationUser;

        $participants = $options['conversationParticipants'];
        if (!is_array($participants))
        {
            \XF::logError('Cannot send welcome message as there are no valid participants to send the message from.');
            return false;
        }

        $starter = array_shift($participants);

        $starterUser = null;
        if ($starter)
        {
            /** @var User $starterUser */
            $starterUser = $this->em()->find('XF:User', $starter);
        }
        if (!$starterUser)
        {
            \XF::logError('Cannot send welcome message as there are no valid participants to send the message from.');
            return false;
        }

        $tokens = $this->prepareTokens(false);
        $language = $this->app->userLanguage($this->user);

        $title = $this->replacePhrases($this->replaceTokens($options['conversationTitle'], $tokens), $language);
        $body = $this->replacePhrases($this->replaceTokens($options['conversationBody'], $tokens), $language);

        $recipients = [];
        if ($participants)
        {
            $recipients = $this->em()->findByIds('XF:User', $participants)->toArray();
        }
        $recipients[$this->user->user_id] = $this->user;

        if ($reply)
        {
            if ($options['conversationEnabled'])
            {
                /** @var \XF\Service\Conversation\Creator $creator */
                $creator = $this->service('XF:Conversation\Creator', $starterUser);
                $creator->setIsAutomated();
                $creator->setOptions([
                    'open_invite' => $options['conversationOpenInvite'],
                    'conversation_open' => !$options['conversationLocked']
                ]);
                $creator->setRecipientsTrusted($recipients);
                $creator->setContent($title, $body);
                if (!$creator->validate($errors))
                {
                    return false;
                }
                $creator->setAutoSendNotifications(false);
                $conversation = $creator->save();

                /** @var \XF\Repository\Conversation $conversationRepo */
                $conversationRepo = $this->app->repository('XF:Conversation');
                $convRecipients = $conversation->getRelationFinder('Recipients')->with('ConversationUser')->fetch();

                $recipientState = ($options['conversationDelete'] == 'delete_ignore' ? 'deleted_ignored' : 'deleted');

                /** @var \XF\Entity\ConversationRecipient $recipient */
                foreach ($convRecipients AS $recipient)
                {
                    if ($recipient->user_id == $this->user->user_id)
                    {
                        continue;
                    }

                    $conversationRepo->markUserConversationRead($recipient->ConversationUser);

                    if ($options['conversationDelete'] != 'no_delete')
                    {
                        $recipient->recipient_state = $recipientState;
                        $recipient->save();
                    }
                }

                /** @var \XF\Service\Conversation\Notifier $notifier */
                $notifier = $this->service('XF:Conversation\Notifier', $conversation);
                $notifier->addNotificationLimit($this->user)->notifyCreate();

                $this->sendConversation = $conversation;
            }
        }

        return $reply;
    }

    /**
     * @param bool $escape
     * @return array
     */
    protected function prepareTokens($escape = true)
    {
        $dateTime = new \DateTime();
        $dateTime->setTimestamp($this->activeUpgrade->start_date);
        $startDate = $dateTime->format('N F Y');
        $dateTime->setTimestamp($this->activeUpgrade->end_date);
        $endDate = $dateTime->format('N F Y');

        $tokens = [
            '{name}' => $this->user->username,
            '{id}' => $this->user->user_id,
            '{upgrade_title}' => $this->userUpgrade->title,
            '{upgrade_start_date}' => $startDate,
            '{upgrade_end_date}' => $endDate
        ];

        if ($escape)
        {
            array_walk($tokens, function(&$value)
            {
                if (is_string($value))
                {
                    $value = htmlspecialchars($value);
                }
            });
        }

        return $tokens;
    }

    /**
     * @param $string
     * @param array $tokens
     * @return string
     */
    protected function replaceTokens($string, array $tokens)
    {
        return strtr($string, $tokens);
    }

    /**
     * @param $string
     * @param \XF\Language $language
     * @return string
     */
    protected function replacePhrases($string, \XF\Language $language)
    {
        return $this->app->stringFormatter()->replacePhrasePlaceholders($string, $language);
    }
}