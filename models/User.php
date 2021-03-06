<?php

namespace app\models;

use app\components\Mailer;
use Yii;
use yii\base\NotSupportedException;
use yii\behaviors\TimestampBehavior;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\web\IdentityInterface;

/**
 * This is the model class for table "user".
 *
 * @property integer $id
 * @property string $email
 * @property string $password_hash
 * @property string $username
 * @property string $auth_key
 * @property integer $confirmed_at
 * @property integer $last_login_at
 * @property integer $updated_at
 * @property integer $created_at
 *
 * @property Token[] $tokens
 */
class User extends \yii\db\ActiveRecord implements IdentityInterface
{
    const REMEMBER_FOR = 86400;
    /**
     * @var string Plain password
     */
    public $password;

    public function behaviors()
    {
        return [
            TimestampBehavior::class,
        ];
    }

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'user';
    }

    /**
     * {@inheritdoc}
     */
    public function scenarios()
    {
        return ArrayHelper::merge(
            parent::scenarios(),
            [
                'register' => ['username', 'email', 'password'],
            ]
        );
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['email', 'username'], 'required'],
            [['email', 'username'], 'string', 'max' => 255],
            [['email'], 'unique'],
            [['email'], 'email'],
            [['username'], 'unique'],
            ['password', 'string', 'min' => 6, 'max' => 72],
            ['password', 'required', 'on' => ['register']],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'email' => 'Email',
            'username' => 'Username',
            'password_hash' => 'Password Hash',
            'auth_key' => 'Auth Key',
            'confirmed_at' => 'Confirmed At',
            'last_login_at' => 'Last Login At',
            'updated_at' => 'Updated At',
            'created_at' => 'Created At',
        ];
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getTokens()
    {
        return $this->hasMany(Token::className(), ['user_id' => 'id']);
    }

    /**
     * Finds an identity by the given ID.
     * @param string|int $id the ID to be looked for
     * @return IdentityInterface the identity object that matches the given ID.
     * Null should be returned if such an identity cannot be found
     * or the identity is not in an active state (disabled, deleted, etc.)
     */
    public static function findIdentity($id)
    {
        return static::findOne($id);
    }

    /**
     * Finds an identity by the given token.
     * @param mixed $token the token to be looked for
     * @param mixed $type the type of the token. The value of this parameter depends on the implementation.
     * For example, [[\yii\filters\auth\HttpBearerAuth]] will set this parameter to be `yii\filters\auth\HttpBearerAuth`.
     * @return IdentityInterface the identity object that matches the given token.
     * Null should be returned if such an identity cannot be found
     * or the identity is not in an active state (disabled, deleted, etc.)
     * @throws NotSupportedException
     */
    public static function findIdentityByAccessToken($token, $type = null)
    {
        throw new NotSupportedException('Method "' . __CLASS__ . '::' . __METHOD__ . '" is not implemented.');
    }

    /**
     * Returns an ID that can uniquely identify a user identity.
     * @return string|int an ID that uniquely identifies a user identity.
     */
    public function getId()
    {
        return $this->getPrimaryKey();
    }

    /**
     * Returns a key that can be used to check the validity of a given identity ID.
     *
     * The key should be unique for each individual user, and should be persistent
     * so that it can be used to check the validity of the user identity.
     *
     * The space of such keys should be big enough to defeat potential identity attacks.
     *
     * This is required if [[User::enableAutoLogin]] is enabled.
     * @return string a key that is used to check the validity of a given identity ID.
     * @see validateAuthKey()
     */
    public function getAuthKey()
    {
        return $this->auth_key;
    }

    /**
     * Validates the given auth key.
     *
     * This is required if [[User::enableAutoLogin]] is enabled.
     * @param string $authKey the given auth key
     * @return bool whether the given auth key is valid.
     * @see getAuthKey()
     */
    public function validateAuthKey($authKey)
    {
        return $this->getAuthKey() === $authKey;
    }

    /**
     * Validates password
     *
     * @param string $password password to validate
     * @return bool if password provided is valid for current user
     */
    public function validatePassword($password)
    {
        return Yii::$app->security->validatePassword($password, $this->password_hash);
    }

    /**
     * Generates password hash from password and sets it to the model
     */
    public function generatePasswordHash()
    {
        if ($this->password !== null) {
            $this->password_hash = Yii::$app->security->generatePasswordHash($this->password);
        }
    }

    /**
     * Generates "remember me" authentication key
     */
    public function generateAuthKey()
    {
        $this->auth_key = Yii::$app->security->generateRandomString();
    }

    /**
     * @inheritdoc
     * @return UserQuery the active query used by this AR class.
     */
    public static function find()
    {
        return new UserQuery(get_called_class());
    }

    public function getIsConfirmed()
    {
        return $this->confirmed_at !== null;
    }

    public function attemptConfirmation($code)
    {
        $token = Token::findOne([
            'user_id' => $this->id,
            'code' => $code,
        ]);
        if ($token instanceof Token && !$token->isExpired) {
            $token->delete();
            if ($this->confirm()) {
                \Yii::$app->user->login($this, self::REMEMBER_FOR);
                return true;
            } else {
                throw new \RuntimeException();
            }
        }
        return false;
    }

    /**
     * Confirms the user by setting 'confirmed_at' field to current time.
     */
    public function confirm()
    {
        $result = (bool)$this->updateAttributes(['confirmed_at' => time()]);
        return $result;
    }

    public function create()
    {
        if ($this->getIsNewRecord() == false) {
            throw new \RuntimeException('Calling "' . __CLASS__ . '::' . __METHOD__ . '" on existing user');
        }

        $transaction = $this->getDb()->beginTransaction();

        try {
            $this->confirmed_at = null;
            if (!$this->save()) {
                $transaction->rollBack();
                return false;
            }

            /** @var Token $token */
            $token = \Yii::createObject(['class' => Token::className()]);
            $token->link('user', $this);

            $this->getMailer()->sendWelcomeMessage($this, isset($token) ? $token : null);

            $transaction->commit();

            return true;
        } catch (\Exception $e) {
            $transaction->rollBack();
            \Yii::warning($e->getMessage());
            throw $e;
        }
    }

    private function getMailer()
    {
        return \Yii::$container->get(Mailer::className());
    }

    public function beforeSave($insert)
    {
        if ($insert) {
            $this->generateAuthKey();
        }
        if (!empty($this->password)) {
            $this->setAttribute(
                'password_hash',
                Yii::$app->security->generatePasswordHash($this->password)
            );
        }
        return parent::beforeSave($insert);
    }
}
