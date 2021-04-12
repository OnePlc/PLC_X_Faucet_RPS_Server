<?php
/**
 * ServerController.php - Main Controller
 *
 * Server Controller for RPSServer Module
 *
 * @category Controller
 * @package Faucet\RPSServer
 * @author Verein onePlace
 * @copyright (C) 2020  Verein onePlace <admin@1plc.ch>
 * @license https://opensource.org/licenses/BSD-3-Clause
 * @version 1.0.0
 * @since 1.0.0
 */

declare(strict_types=1);

namespace OnePlace\Faucet\RPSServer\Controller;

use Application\Controller\CoreEntityController;
use Application\Model\CoreEntityModel;
use Laminas\View\Model\ViewModel;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Where;
use Laminas\Db\TableGateway\TableGateway;
use Laminas\Http\ClientStatic;
use OnePlace\User\Model\UserTable;
use OnePlace\Faucet\Tgbot\Controller\TelegramController;

class ServerController extends CoreEntityController
{
    /**
     * Faucet Table Object
     *
     * @since 1.0.0
     */
    protected $oTableGateway;

    /**
     * FaucetController constructor.
     *
     * @param AdapterInterface $oDbAdapter
     * @param UserTable $oTableGateway
     * @param $oServiceManager
     * @since 1.0.0
     */
    public function __construct(AdapterInterface $oDbAdapter,UserTable $oTableGateway,$oServiceManager)
    {
        $this->oTableGateway = $oTableGateway;
        $this->sSingleForm = 'faucet-rpsserver-single';

        if(isset(CoreEntityController::$oSession->oUser)) {
            setlocale(LC_TIME, CoreEntityController::$oSession->oUser->lang);
        }

        parent::__construct($oDbAdapter,$oTableGateway,$oServiceManager);

        if($oTableGateway) {
            # Attach TableGateway to Entity Models
            if(!isset(CoreEntityModel::$aEntityTables[$this->sSingleForm])) {
                CoreEntityModel::$aEntityTables[$this->sSingleForm] = $oTableGateway;
            }
        }
    }

    /**
     * RPS Live Stats
     *
     * Get open Games for User and globally
     *
     * @param false $oUser
     * @return array
     * @since 1.0.2
     */
    public static function getRPSLiveStats($oUser = false) {
        $iMyGames = 0;
        $iTotalGames = 0;

        $oSessionsTbl = new TableGateway('faucet_game_match', CoreEntityController::$oDbAdapter);
        $iTotalGames = $oSessionsTbl->select([
            'client_user_idfs' => 0,
            'active' => 1,
        ])->count();
        if($oUser) {
            $iMyGames = $oSessionsTbl->select([
                'host_user_idfs' => $oUser->getID(),
                'client_user_idfs' => 0,
                'active' => 1,
            ])->count();
        }

        return [
            'iTotalGames' => $iTotalGames,
            'iMyGames' => $iMyGames,
        ];
    }

    public static function cancelRPSGame($oUser, $iMatchID) {
        $oSessionsTbl = new TableGateway('faucet_game_match', CoreEntityController::$oDbAdapter);

        $oOpenGame = $oSessionsTbl->select([
            'host_user_idfs' => $oUser->getID(),
            'Match_ID' => $iMatchID,
            'client_user_idfs' => 0,
        ]);

        if(count($oOpenGame) > 0) {
            if(is_numeric($iMatchID) && $iMatchID != 0 && $iMatchID != '') {
                $oMatch = ServerController::loadRPSGame($iMatchID);
                # give back bet to host who already paid
                $oUserTbl = new TableGateway('user', CoreEntityController::$oDbAdapter);
                $fCurrentBalance = $oUserTbl->select(['User_ID' => $oUser->getID()])->current()->token_balance;
                $oUserTbl->update([
                    'token_balance' => $fCurrentBalance+$oMatch->amount_bet,
                ],'User_ID = '.$oUser->getID());
                $oSessionsTbl->delete(['Match_ID' => $iMatchID]);
            }
            return true;
        } else {
            return false;
        }
    }

    public static function loadRPSHistory($oUser) {
        $oSessionsTbl = new TableGateway('faucet_game_match', CoreEntityController::$oDbAdapter);
        $oUserTbl = CoreEntityController::$oServiceManager->get(UserTable::class);

        $oWh = new Where();
        $oWh->notEqualTo('client_user_idfs', 0);
        $oWh->NEST
            ->equalTo('host_user_idfs', $oUser->getID())
            ->OR
            ->equalTo('client_user_idfs', $oUser->getID())
            ->UNNEST;
        $oWh->like('game', 'rps');

        $oSel = new Select($oSessionsTbl->getTable());
        $oSel->where($oWh);
        $oSel->order('date_matched DESC');
        $oSel->limit(3);

        $oRecentGamesDB = $oSessionsTbl->selectWith($oSel);

        $aRecentGames = [];
        if(count($oRecentGamesDB) > 0) {
            foreach($oRecentGamesDB as $oGame) {
                if($oGame->host_user_idfs == $oUser->getID()) {
                    $oClient = $oUserTbl->getSingle($oGame->client_user_idfs);
                    $oGame->oEnemy = (object)['id' => $oClient->getID(),'name' => $oClient->getLabel()];
                } else {
                    $oHost = $oUserTbl->getSingle($oGame->host_user_idfs);
                    $oGame->oEnemy = (object)['id' => $oHost->getID(),'name' => $oHost->getLabel()];
                }
                $oGame->sTimeSince = ServerController::timeElapsedString($oGame->date_matched);
                $aRecentGames[] = $oGame;
            }
        }

        return $aRecentGames;
    }

    public static function getPreparedRPSGame($oUser) {
        $oSessionsTbl = new TableGateway('faucet_game_match', CoreEntityController::$oDbAdapter);
        $oOpenGame = $oSessionsTbl->select([
            'host_user_idfs' => $oUser->getID(),
            'game' => 'rps',
            'active' => 0,
        ]);

        if(count($oOpenGame) > 0) {
            return $oOpenGame->current();
        } else {
            return false;
        }
    }

    public static function prepareRPSGame($oUser,$fBet) {
        if($fBet < 0) {
            $fBet = 0-$fBet;
        }

        if($oUser->token_balance < $fBet) {
            $aReturn = [
                'state' => 'error',
                'message' => 'Your balance is too low',
            ];
            return $aReturn;
        }

        $oSessionsTbl = new TableGateway('faucet_game_match', CoreEntityController::$oDbAdapter);
        $iAutoMatch = 1;
        $oSessionsTbl->insert([
            'host_user_idfs' => $oUser->getID(),
            'client_user_idfs' => 0,
            'game' => 'rps',
            'date_created' => date('Y-m-d H:i:s', time()),
            'date_matched' => '0000-00-00 00:00:00',
            'host_vote' => 0,
            'client_vote' => 0,
            'amount_bet' => (float)$fBet,
            'auto_match' => $iAutoMatch,
            'active' => 0,
        ]);

        $aReturn = [
            'state' => 'success',
            'message' => 'game ready',
        ];
        return $aReturn;
    }

    public static function matchRPSGame($iMatchID, $iClientVote, $iClientID = 0) {
        $oSessionsTbl = new TableGateway('faucet_game_match', CoreEntityController::$oDbAdapter);
        $oUserTbl = new TableGateway('user', CoreEntityController::$oDbAdapter);
        $oUsrServ = CoreEntityController::$oServiceManager->get(\OnePlace\User\Model\UserTable::class);
        $oSessionsOpen = $oSessionsTbl->select(['Match_ID' => $iMatchID]);
        if(count($oSessionsOpen) > 0) {
            $oSession = $oSessionsOpen->current();
            $iClientID = ($iClientID == 0) ? $oSession->client_user_idfs : $iClientID;
            //$oClientUser = $oUserTbl->select(['User_ID' => $oSession->client_user_idfs]);
            $oClientUser = $oUsrServ->getSingle($iClientID);
            $oHostUser = $oUsrServ->getSingle($oSession->host_user_idfs);

            if($oSession->amount_bet > $oClientUser->token_balance) {
                return 'clientbalancetoosmall';
            }
            /**
             * Determine Winner
             */
            $sResult = 'even';
            $sMeEmote = '';
            $sHostEmote = '';
            $iWinnerID = 0;
            if($iClientVote == 1) {
                $sMeEmote = '🗿 Rock';
                if($oSession->host_vote == 1) {
                    $sHostEmote = '🗿 Rock';
                    $oSession = '🗿 Rock';
                } elseif ($oSession->host_vote == 2) {
                    $sHostEmote = '📝️️️ Paper';
                    $sResult = 'lost';
                    $iWinnerID = $oSession->host_user_idfs;
                } elseif ($oSession->host_vote == 3) {
                    $sHostEmote = '✂️ Scissors';
                    $sResult = 'won';
                    $iWinnerID = $iClientID;
                }
            } elseif($iClientVote == 2) {
                $sMeEmote = '📝️️️ Paper';
                if($oSession->host_vote == 1) {
                    $sHostEmote = '🗿 Rock';
                    $sResult = 'won';
                    $iWinnerID = $iClientID;
                } elseif ($oSession->host_vote == 2) {
                    $sHostEmote = '📝️️️ Paper';
                } elseif ($oSession->host_vote == 3) {
                    $sHostEmote = '✂️ Scissors';
                    $sResult = 'lost';
                    $iWinnerID = $oSession->host_user_idfs;
                }
            } elseif($iClientVote == 3) {
                $sMeEmote = '✂️ Scissors';
                if($oSession->host_vote == 1) {
                    $sHostEmote = '🗿 Rock';
                    $sResult = 'lost';
                    $iWinnerID = $oSession->host_user_idfs;
                } elseif ($oSession->host_vote == 2) {
                    $sHostEmote = '📝️️️ Paper';
                    $sResult = 'won';
                    $iWinnerID = $iClientID;
                } elseif ($oSession->host_vote == 3) {
                    $sHostEmote = '✂️ Scissors';
                }
            }

            $aMatchData = [
                'client_vote' => $iClientVote,
                'winner_idfs' => $iWinnerID,
                'date_matched' => date('Y-m-d H:i:s', time()),
            ];
            if($oSession->client_user_idfs == 0) {
                $aMatchData['client_user_idfs'] = $iClientID;
            }

            $oSessionsTbl->update($aMatchData,[
                'Match_ID' => $iMatchID,
            ]);

            /**
             * Update Users Token Balances
             */
            switch($sResult) {
                case 'won':
                    if($oSession->host_user_idfs != 0) {
                        $fHostBalance = $oUserTbl->select(['User_ID' => $oSession->host_user_idfs])->current()->token_balance;
                        $oUserTbl->update([
                            'token_balance' => $fHostBalance,
                        ],'User_ID = '.$oSession->host_user_idfs);
                    }
                    if($iClientID != 0 && $iClientID != '') {
                        $fNewBalanceMe = $oUserTbl->select(['User_ID' => $iClientID])->current()->token_balance;
                        $oUserTbl->update([
                            'token_balance' => $fNewBalanceMe + $oSession->amount_bet,
                        ],'User_ID = '.$iClientID);
                    }
                    break;
                case 'lost':
                    if($oSession->host_user_idfs != 0 && $oSession->host_user_idfs != '') {
                        $fHostBalance = $oUserTbl->select(['User_ID' => $oSession->host_user_idfs])->current()->token_balance;
                        $oUserTbl->update([
                            'token_balance' => $fHostBalance + ($oSession->amount_bet*2),
                        ],'User_ID = '.$oSession->host_user_idfs);
                    }
                    if($iClientID != 0 && $iClientID != '') {
                        $fNewBalanceMe = $oUserTbl->select(['User_ID' => $iClientID])->current()->token_balance;
                        $oUserTbl->update([
                            'token_balance' => $fNewBalanceMe - $oSession->amount_bet,
                        ],'User_ID = '.$iClientID);
                    }
                    break;
                case 'even':
                    if($oSession->host_user_idfs != 0 && $oSession->host_user_idfs != '') {
                        $fHostBalance = $oUserTbl->select(['User_ID' => $oSession->host_user_idfs])->current()->token_balance;
                        $oUserTbl->update([
                            'token_balance' => $fHostBalance + $oSession->amount_bet,
                        ],'User_ID = '.$oSession->host_user_idfs);
                    }
                    if($iClientID != 0 && $iClientID != '') {
                        $fNewBalanceMe = $oUserTbl->select(['User_ID' => $iClientID])->current()->token_balance;
                        $oUserTbl->update([
                            'token_balance' => $fNewBalanceMe,
                        ],'User_ID = '.$iClientID);
                    }
                    break;
                default:
                    break;
            }

            /**
             * Notify Users
             */
            if($oClientUser) {
                if($oClientUser->telegram_chatid != '' && $oClientUser->getSetting('tgbot-gamenotifications') == 'on') {
                    if($sResult == 'even') {
                        $aMessage = [
                            'chat_id' => $oClientUser->telegram_chatid,
                            'text' => "the game vs ".$oHostUser->username." is even - ".$sMeEmote.' VS '.$sHostEmote.' # +0 Coins ',
                        ];
                        ServerController::sendTelegramMessage($aMessage);
                    } else {
                        $sMePre = ($sResult == 'won') ? '+' : '-';
                        $aMessage = [
                            'chat_id' => $oClientUser->telegram_chatid,
                            'text' => "You have ".$sResult." the game vs ".$oHostUser->username." - ".$sMeEmote.' VS '.$sHostEmote.' # '.$sMePre.number_format((float)$oSession->amount_bet,2,'.','\'').' Coins ',
                        ];
                        ServerController::sendTelegramMessage($aMessage);
                    }
                }
            }

            if($oHostUser) {
                if($oHostUser->telegram_chatid != '' && $oHostUser->getSetting('tgbot-gamenotifications') == 'on') {
                    if($sResult == 'even') {
                        $aMessage = [
                            'chat_id' => $oHostUser->telegram_chatid,
                            'text' => "the game vs ".$oClientUser->username." is even - ".$sHostEmote.' VS '.$sMeEmote.' # +0 Coins ',
                        ];
                        ServerController::sendTelegramMessage($aMessage);
                    } else {
                        $sHostResult = ($sResult == 'won') ? 'lost' : 'won';
                        $sHostPre = ($sResult == 'won') ? '-' : '+';
                        $aMessage = [
                            'chat_id' => $oHostUser->telegram_chatid,
                            'text' => "You have ".$sHostResult." the game vs ".$oClientUser->username." - ".$sHostEmote.' VS '.$sMeEmote.' # '.$sHostPre.number_format((float)$oSession->amount_bet,2,'.','\'').' Coins ',
                        ];
                        ServerController::sendTelegramMessage($aMessage);
                    }
                }
            }

        }

        if(isset($sResult)) {
            return $sResult;
        } else {
            return false;
        }
    }

    public static function joinRPSGame($iMatchID, $iClientID, $iVote = 0) {
        $oSessionsTbl = new TableGateway('faucet_game_match', CoreEntityController::$oDbAdapter);

        $oWh = new Where();
        $oWh->equalTo('Match_ID', $iMatchID);
        $oWh->like('game', 'rps');
        $oWh->equalTo('active', 1);

        $oSessionsOpen = $oSessionsTbl->select($oWh);

        if(count($oSessionsOpen) > 0) {
            $aData = [
                'client_user_idfs' => $iClientID,
            ];
            if($iVote != 0) {
                $aData['client_vote'] = $iVote;
            }
            $oSessionsTbl->update($aData,'Match_ID = '.$iMatchID);

            return true;
        } else {
            return false;
        }
    }

    public static function loadRPSGames($oUser, $sRole = 'host') {
        $oSessionsTbl = new TableGateway('faucet_game_match', CoreEntityController::$oDbAdapter);
        $oUserTbl = CoreEntityController::$oServiceManager->get(UserTable::class);

        $oWh = new Where();
        $oWh->equalTo('client_user_idfs', 0);
        if($sRole == 'host') {
            $oWh->equalTo('host_user_idfs', $oUser->getID());
        } else {
            $oWh->notEqualTo('host_user_idfs', $oUser->getID());
        }
        $oWh->like('game', 'rps');
        $oWh->equalTo('active', 1);

        $oLoadSel = new Select($oSessionsTbl->getTable());
        $oLoadSel->where($oWh);
        $oLoadSel->limit(20);

        $aSessionsOpen = $oSessionsTbl->selectWith($oLoadSel);

        $aSessions = [];
        if(count($aSessionsOpen) > 0) {
            foreach($aSessionsOpen as $oGame) {
                if($sRole == 'client') {
                    $oHost = $oUserTbl->getSingle($oGame->host_user_idfs);
                    $oGame->oEnemy = (object)['id' => $oHost->getID(),'name' => $oHost->getLabel()];
                }
                $oGame->sTimeSince = ServerController::timeElapsedString($oGame->date_created);
                # Overwrite Host Vote to hide it in API
                $oGame->host_vote = 0;
                $aSessions[] = $oGame;
            }
        }

        return $aSessions;
    }

    public static function startRPSGame($iVote,$fBet,$oUser)
    {
        if($fBet < 0) {
            $fBet = 0-$fBet;
        }

        if($oUser->token_balance < $fBet) {
            $aReturn = [
                'state' => 'error',
                'message' => 'Your balance is too low',
            ];
            return $aReturn;
        }


        $oSessionsTbl = new TableGateway('faucet_game_match', CoreEntityController::$oDbAdapter);
        $oWh = new Where();
        $oWh->equalTo('client_user_idfs', 0);
        $oWh->equalTo('host_user_idfs', $oUser->getID());
        $oWh->like('game', 'rps');
        $oWh->equalTo('active', 1);

        $iMatchLimit = 0;
        if($oUser->getSetting('game-rps-unlock-multi')) {
            $iMatchLimit = $oUser->getSetting('game-rps-unlock-multi')-1;
        }

        $oSessionOpen = $oSessionsTbl->select($oWh);
        if(count($oSessionOpen) <= $iMatchLimit) {
            $iAutoMatch = 1;
            $oOpenGame = $oSessionsTbl->select([
                'host_user_idfs' => $oUser->getID(),
                'game' => 'rps',
                'active' => 0,
            ]);
            if(count($oOpenGame) == 0) {
                $oSessionsTbl->insert([
                    'host_user_idfs' => $oUser->getID(),
                    'client_user_idfs' => 0,
                    'game' => 'rps',
                    'date_created' => date('Y-m-d H:i:s', time()),
                    'date_matched' => '0000-00-00 00:00:00',
                    'host_vote' => $iVote,
                    'client_vote' => 0,
                    'amount_bet' => (float)$fBet,
                    'auto_match' => $iAutoMatch,
                ]);
            } else {
                $oOpenGame = $oOpenGame->current();
                $oSessionsTbl->update([
                    'host_vote' => $iVote,
                    'active' => 1,
                ],'Match_ID = '.$oOpenGame->Match_ID);
            }

            $oUserTbl = new TableGateway('user', CoreEntityController::$oDbAdapter);
            $fCurrentBalance = $oUserTbl->select(['User_ID' => $oUser->getID()])->current()->token_balance;
            if(isset(CoreEntityController::$oSession->oUser)) {
                CoreEntityController::$oSession->oUser->token_balance = $fCurrentBalance-$fBet;
            }
            $oUserTbl->update([
                'token_balance' => $fCurrentBalance-$fBet,
            ],'User_ID = '.$oUser->getID());

            $aReturn = [
                'state' => 'success',
                'message' => 'game created',
            ];
            return $aReturn;
        } else {
            $aReturn = [
                'state' => 'error',
                'message' => 'game limit reached',
            ];
            return $aReturn;
        }
    }

    public static function loadRPSGame($iMatchID) {
        $oSessionsTbl = new TableGateway('faucet_game_match', CoreEntityController::$oDbAdapter);

        $oGameFound = $oSessionsTbl->select(['Match_ID' => $iMatchID]);
        if(count($oGameFound) > 0) {
            return $oGameFound->current();
        } else {
            return false;
        }
    }

    public function matchrpsAction()
    {
        $this->layout('layout/json');

        $sInfo = $this->params()->fromRoute('id','0-0');
        $aInfo = explode('-', $sInfo);

        $iMatchID = $aInfo[0];
        $iVote = $aInfo[1];
        $oMe = CoreEntityController::$oSession->oUser;

        $oSessionsTbl = $this->getCustomTable('faucet_game_match', CoreEntityController::$oDbAdapter);
        $oWh = new Where();
        $oWh->equalTo('Match_ID', $iMatchID);
        $oWh->like('game', 'rps');

        $oSessionOpen = $oSessionsTbl->select($oWh);
        if(count($oSessionOpen) > 0) {
            $oSessionOpen = $oSessionOpen->current();
            $iHostID = $oSessionOpen->host_user_idfs;
            $sResult = 'even';
            $sMeEmote = '';
            $sHostEmote = '';
            if($iVote == 1) {
                $sMeEmote = '🗿 Rock';
                if($oSessionOpen->host_vote == 1) {
                    $sHostEmote = '🗿 Rock';
                    $this->flashMessenger()->addWarningMessage('Rock - Rock - Even');
                } elseif ($oSessionOpen->host_vote == 2) {
                    $sHostEmote = '📝️️️ Paper';
                    $this->flashMessenger()->addErrorMessage('Rock - Paper - Lost');
                    $sResult = 'lost';
                } elseif ($oSessionOpen->host_vote == 3) {
                    $sHostEmote = '✂️ Scissors';
                    $this->flashMessenger()->addSuccessMessage('Rock - Scissors - Won');
                    $sResult = 'won';
                }
            } elseif($iVote == 2) {
                $sMeEmote = '📝️️️ Paper';
                if($oSessionOpen->host_vote == 1) {
                    $sHostEmote = '🗿 Rock';
                    $this->flashMessenger()->addSuccessMessage('Paper - Rock - Won');
                    $sResult = 'won';
                } elseif ($oSessionOpen->host_vote == 2) {
                    $sHostEmote = '📝️️️ Paper';
                    $this->flashMessenger()->addWarningMessage('Paper - Paper - Even');
                } elseif ($oSessionOpen->host_vote == 3) {
                    $sHostEmote = '✂️ Scissors';
                    $this->flashMessenger()->addErrorMessage('Paper - Scissors - Lost');
                    $sResult = 'lost';
                }
            } elseif($iVote == 3) {
                $sMeEmote = '✂️ Scissors';
                if($oSessionOpen->host_vote == 1) {
                    $sHostEmote = '🗿 Rock';
                    $this->flashMessenger()->addErrorMessage('Scissors - Rock - Lost');
                    $sResult = 'lost';
                } elseif ($oSessionOpen->host_vote == 2) {
                    $sHostEmote = '📝️️️ Paper';
                    $this->flashMessenger()->addSuccessMessage('Scissors - Paper - Won');
                    $sResult = 'won';
                } elseif ($oSessionOpen->host_vote == 3) {
                    $sHostEmote = '✂️ Scissors';
                    $this->flashMessenger()->addWarningMessage('Scissors - Scissors - Even');
                }
            }

            $oUserTbl = $this->getCustomTable('user');
            $oHost = $this->oTableGateway->getSingle($oSessionOpen->host_user_idfs);

            $oSessionsTbl->update([
                'client_user_idfs' => $oMe->getID(),
                'client_vote' => $iVote,
                'winner_idfs' => ($sResult == 'lost') ? $iHostID : $oMe->getID(),
                'date_matched' => date('Y-m-d H:i:s', time()),
            ],[
                'Match_ID' => $iMatchID,
            ]);

            $fNewBalanceMe = $oMe->token_balance;
            switch($sResult) {
                case 'won':
                    if($oHost->getID() != 0 && $oHost->getID() != '') {
                        $fHostBalance = $oUserTbl->select(['User_ID' => $oHost->getID()])->current()->token_balance;
                        $oUserTbl->update([
                            'token_balance' => $fHostBalance,
                        ],'User_ID = '.$oHost->getID());
                    }
                    if($oMe->getID() != 0 && $oMe->getID() != '') {
                        $fNewBalanceMe = $oUserTbl->select(['User_ID' => $oMe->getID()])->current()->token_balance;
                        $oUserTbl->update([
                            'token_balance' => $fNewBalanceMe + $oSessionOpen->amount_bet,
                        ],'User_ID = '.$oMe->getID());
                    }
                    break;
                case 'lost':
                    if($oHost->getID() != 0 && $oHost->getID() != '') {
                        $fHostBalance = $oUserTbl->select(['User_ID' => $oHost->getID()])->current()->token_balance;
                        $oUserTbl->update([
                            'token_balance' => $fHostBalance + ($oSessionOpen->amount_bet*2),
                        ],'User_ID = '.$oHost->getID());
                    }
                    if($oMe->getID() != 0 && $oMe->getID() != '') {
                        $fNewBalanceMe = $oUserTbl->select(['User_ID' => $oMe->getID()])->current()->token_balance;
                        $oUserTbl->update([
                            'token_balance' => $fNewBalanceMe - $oSessionOpen->amount_bet,
                        ],'User_ID = '.$oMe->getID());
                    }
                    break;
                case 'even':
                    if($oHost->getID() != 0 && $oHost->getID() != '') {
                        $fHostBalance = $oUserTbl->select(['User_ID' => $oHost->getID()])->current()->token_balance;
                        $oUserTbl->update([
                            'token_balance' => $fHostBalance + $oSessionOpen->amount_bet,
                        ],'User_ID = '.$oHost->getID());
                    }
                    if($oMe->getID() != 0 && $oMe->getID() != '') {
                        $fNewBalanceMe = $oUserTbl->select(['User_ID' => $oMe->getID()])->current()->token_balance;
                        $oUserTbl->update([
                            'token_balance' => $fNewBalanceMe,
                        ],'User_ID = '.$oMe->getID());
                    }
                    break;
                default:
                    break;
            }

            CoreEntityController::$oSession->oUser->token_balance = $fNewBalanceMe;
            if($oMe->telegram_chatid != '' && $oMe->getSetting('tgbot-gamenotifications') == 'on') {
                if($sResult == 'even') {
                    $aMessage = [
                        'chat_id' => $oMe->telegram_chatid,
                        'text' => "the game vs ".$oHost->getLabel()." is even - ".$sMeEmote.' VS '.$sHostEmote.' # +0 Coins ',
                    ];
                    ServerController::sendTelegramMessage($aMessage);
                } else {
                    $sMePre = ($sResult == 'won') ? '+' : '-';
                    $aMessage = [
                        'chat_id' => $oMe->telegram_chatid,
                        'text' => "You have ".$sResult." the game vs ".$oHost->getLabel()." - ".$sMeEmote.' VS '.$sHostEmote.' # '.$sMePre.number_format((float)$oSessionOpen->amount_bet,2,'.','\'').' Coins ',
                    ];
                    ServerController::sendTelegramMessage($aMessage);
                }
            }
            if($oHost->telegram_chatid != '' && $oHost->getSetting('tgbot-gamenotifications') == 'on') {
                if($sResult == 'even') {
                    $aMessage = [
                        'chat_id' => $oHost->telegram_chatid,
                        'text' => "the game vs ".$oMe->getLabel()." is even - ".$sHostEmote.' VS '.$sMeEmote.' # +0 Coins ',
                    ];
                    ServerController::sendTelegramMessage($aMessage);
                } else {
                    $sHostResult = ($sResult == 'won') ? 'lost' : 'won';
                    $sHostPre = ($sResult == 'won') ? '-' : '+';
                    $aMessage = [
                        'chat_id' => $oHost->telegram_chatid,
                        'text' => "You have ".$sHostResult." the game vs ".$oMe->getLabel()." - ".$sHostEmote.' VS '.$sMeEmote.' # '.$sHostPre.number_format((float)$oSessionOpen->amount_bet,2,'.','\'').' Coins ',
                    ];
                    ServerController::sendTelegramMessage($aMessage);
                }
            }

            return $this->redirect()->toRoute('faucet-games', ['action' => 'rockpaperscissors']);
        } else {
            $this->flashMessenger()->addErrorMessage('Game is not open anymore');

        }

        return $this->redirect()->toRoute('faucet-games', ['action' => 'rockpaperscissors']);
    }

    public static function timeElapsedString($datetime, $full = false) {
        $now = new \DateTime;
        $ago = new \DateTime($datetime);
        $diff = $now->diff($ago);

        $diff->w = floor($diff->d / 7);
        $diff->d -= $diff->w * 7;

        $string = array(
            'y' => 'year',
            'm' => 'month',
            'w' => 'week',
            'd' => 'day',
            'h' => 'hour',
            'i' => 'minute',
            's' => 'second',
        );
        foreach ($string as $k => &$v) {
            if ($diff->$k) {
                $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
            } else {
                unset($string[$k]);
            }
        }

        if (!$full) $string = array_slice($string, 0, 1);
        return $string ? implode(', ', $string) . ' ago' : 'just now';
    }

    private static function sendTelegramMessage($aContent)
    {
        $sToken = CoreEntityController::$aGlobalSettings['tgbot-token'];
        $ch = curl_init();
        $url="https://api.telegram.org/bot$sToken/SendMessage";
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($aContent));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        // receive server response ...
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $server_output = curl_exec ($ch);
        curl_close ($ch);
    }
}
