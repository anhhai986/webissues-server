<?php
/**************************************************************************
* This file is part of the WebIssues Server program
* Copyright (C) 2006 Michał Męciński
* Copyright (C) 2007-2013 WebIssues Team
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU Affero General Public License for more details.
*
* You should have received a copy of the GNU Affero General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
**************************************************************************/

if ( !defined( 'WI_VERSION' ) ) die( -1 );

/**
* Manage subscriptions for current user.
*
* Like all API classes, this class does not check permissions to perform
* an operation and does not validate the input values. An error is thrown
* only if the requested object does not exist or is inaccessible.
*/
class System_Api_SubscriptionManager extends System_Api_Base
{
    /**
    * Constructor.
    */
    public function __construct()
    {
        parent::__construct();
    }

    /**
    * Get the current user's subscription with given identifier.
    * @param $subscriptionId Identifier of the subscription.
    * @return Subscription as an associative array.
    */
    public function getSubscription( $subscriptionId )
    {
        $principal = System_Api_Principal::getCurrent();

        $query = 'SELECT subscription_id, issue_id, user_id, stamp_id'
            . ' FROM {subscriptions}'
            . ' WHERE subscription_id = %d AND user_id = %d';

        if ( !( $subscription = $this->connection->queryRow( $query, $subscriptionId, $principal->getUserId() ) ) )
            throw new System_Api_Error( System_Api_Error::UnknownSubscription );

        return $subscription;
    }

    /**
    * Get the current user's subscription for given issue.
    * @param $issue The issue associated with the subscription.
    * @return Subscription as an associative array.
    */
    public function getSubscriptionForIssue( $issue )
    {
        $subscriptionId = $issue[ 'subscription_id' ];

        if ( $subscriptionId == null )
            throw new System_Api_Error( System_Api_Error::UnknownSubscription );

        return $this->getSubscription( $subscriptionId );
    }

    /**
    * Find the subscription for given issue and email address.
    * @param $issue The issue to return the subscription for.
    * @param $email The email address associated with the subscription.
    * @return Subscription as an associative array or @c false if it doesn't exist.
    */
    public function findEmailSubscription( $issue, $email )
    {
        $issueId = $issue[ 'issue_id' ];

        $query = 'SELECT subscription_id, issue_id, user_email, stamp_id'
            . ' FROM {subscriptions}'
            . ' WHERE issue_id = %d AND user_id IS NULL AND user_email = %s';

        return $this->connection->queryRow( $query, $issueId, $email );
    }

    /**
    * Add a subscription for the current user for given issue. An error is thrown
    * if it already exists.
    * @param $issue The issue to create the subscription for.
    * @retrun The identifier of the subscription.
    */
    public function addSubscription( $issue )
    {
        $principal = System_Api_Principal::getCurrent();

        $issueId = $issue[ 'issue_id' ];
        $stampId = $issue[ 'stamp_id' ];
        $stateId = $issue[ 'state_id' ];
        $readId = $issue[ 'read_id' ];

        $transaction = $this->connection->beginTransaction( System_Db_Transaction::Serializable, 'subscriptions' );

        try {
            $query = 'SELECT subscription_id FROM {subscriptions} WHERE issue_id = %d AND user_id = %d';
            if ( $this->connection->queryScalar( $query, $issueId, $principal->getUserId() ) !== false )
                throw new System_Api_Error( System_Api_Error::SubscriptionAlreadyExists );

            $query = 'INSERT INTO {subscriptions} ( issue_id, user_id, user_email, stamp_id ) VALUES ( %d, %d, NULL, %d )';
            $this->connection->execute( $query, $issueId, $principal->getUserId(), $stampId );

            $subscriptionId = $this->connection->getInsertId( 'subscriptions', 'subscription_id' );

            if ( $stateId != null ) {
                $query = 'DELETE FROM {issue_states} WHERE state_id = %d';
                $this->connection->execute( $query, $stateId );
            }

            $query = 'INSERT INTO {issue_states} ( user_id, issue_id, read_id, subscription_id )';
            if ( $readId > 0 )
                $query .= ' VALUES ( %1d, %2d, %3d, %4d )';
            else
                $query .= ' VALUES ( %1d, %2d, NULL, %4d )';
            $this->connection->execute( $query, $principal->getUserId(), $issueId, $readId, $subscriptionId );

            $transaction->commit();
        } catch ( Exception $ex ) {
            $transaction->rollback();
            throw $ex;
        }

        return $subscriptionId;
    }

    /**
    * Add a subscription for the given email address for given issue. An error
    * is thrown if it already exists.
    * @param $issue The issue to create the subscription for.
    * @param $email The email address to create the subscription for.
    * @retrun The identifier of the subscription.
    */
    public function addEmailSubscription( $issue, $email )
    {
        $issueId = $issue[ 'issue_id' ];
        $stampId = $issue[ 'stamp_id' ];

        $transaction = $this->connection->beginTransaction( System_Db_Transaction::Serializable, 'subscriptions' );

        try {
            $query = 'SELECT subscription_id FROM {subscriptions} WHERE issue_id = %d AND user_id IS NULL AS user_email = %s';
            if ( $this->connection->queryScalar( $query, $issueId, $email ) !== false )
                throw new System_Api_Error( System_Api_Error::SubscriptionAlreadyExists );

            $query = 'INSERT INTO {subscriptions} ( issue_id, user_id, user_email, stamp_id ) VALUES ( %d, NULL, %s, %d )';
            $this->connection->execute( $query, $issueId, $email, $stampId );

            $subscriptionId = $this->connection->getInsertId( 'subscriptions', 'subscription_id' );

            $transaction->commit();
        } catch ( Exception $ex ) {
            $transaction->rollback();
            throw $ex;
        }

        return $subscriptionId;
    }

    /**
    * Delete a subscription.
    * @param $issue The issue associated with the subscription.
    * @param $subscription The subscription to delete.
    * @return @c true if the alert was deleted.
    */
    public function deleteSubscription( $issue, $subscription )
    {
        $subscriptionId = $subscription[ 'subscription_id' ];
        $userId = $subscription[ 'user_id' ];

        if ( $issue[ 'issue_id' ] != $subscription[ 'issue_id' ] )
            throw new System_Api_Error( System_Api_Error::UnknownSubscription );

        $transaction = $this->connection->beginTransaction( System_Db_Transaction::Serializable, 'subscriptions' );

        try {
            $query = 'DELETE FROM {subscriptions} WHERE subscription_id = %d';
            $this->connection->execute( $query, $subscriptionId );

            if ( $userId != null ) {
                $issueId = $issue[ 'issue_id' ];
                $stateId = $issue[ 'state_id' ];
                $readId = $issue[ 'read_id' ];

                if ( $stateId != null ) {
                    $query = 'DELETE FROM {issue_states} WHERE state_id = %d';
                    $this->connection->execute( $query, $stateId );
                }

                $query = 'INSERT INTO {issue_states} ( user_id, issue_id, read_id, subscription_id )';
                if ( $readId > 0 )
                    $query .= ' VALUES ( %1d, %2d, %3d, NULL )';
                else
                    $query .= ' VALUES ( %1d, %2d, NULL, NULL )';
                $this->connection->execute( $query, $userId, $issueId, $readId );
            }

            $transaction->commit();
        } catch ( Exception $ex ) {
            $transaction->rollback();
            throw $ex;
        }

        return true;
    }

    /**
    * Associate given change with a subscription.
    * @param $changeId Identifier of the change.
    * @param $subscription The subscription to associate.
    */
    public function setSubscriptionForChange( $changeId, $subscription )
    {
        $subscriptionId = $subscription[ 'subscription_id' ];

        $query = 'UPDATE {changes} SET subscription_id = %d WHERE change_id = %d';
        $this->connection->execute( $query, $subscriptionId, $changeId );
    }

    /**
    * Get all subscriptions for which an email should be sent.
    * @return An array of associative arrays represeting subscriptions.
    */
    public function getSubscriptionsToEmail()
    {
        $query = 'SELECT s.subscription_id, s.issue_id, s.user_id, COALESCE( p.pref_value, s.user_email ) AS user_email, s.stamp_id'
            . ' FROM {subscriptions} AS s'
            . ' JOIN {issues} AS i ON i.issue_id = s.issue_id'
            . ' LEFT OUTER JOIN {preferences} AS p ON p.user_id = s.user_id AND p.pref_key = %s'
            . ' WHERE i.stamp_id > s.stamp_id AND COALESCE( p.pref_value, s.user_email ) IS NOT NULL';

        return $this->connection->queryTable( $query, 'email' );
    }

    /**
    * Update the stamp of last sent email for given subscription.
    * @param $subscription The subscription to update.
    */
    public function updateSubscriptionStamp( $subscription )
    {
        $subscriptionId = $subscription[ 'subscription_id' ];
        $issueId = $subscription[ 'issue_id' ];

        $query = 'UPDATE {subscription}'
            . ' SET stamp_id = ( SELECT stamp_id FROM {issues} AS i WHERE i.issue_id = %d )'
            . ' WHERE subscription_id = %d';

        $this->connection->execute( $issueId, $subscriptionId );
    }
}
