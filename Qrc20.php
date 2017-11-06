<?php 
/**
 * Created on November, 6th 2017
 *
 * Qtum PHP script to showcase token operations
 * Requires php gmp lib
 * qtumd should be started with -logevents (and -txindex if the node running this is not the one with the deposit addresses)
 * 
 * This script is just a showcase of how to implement QRC20 operations, it cannot be used for production
 * For your production environemnt, make sure you have all validations, and errors handling and security tested
 * 
 * @author qtum-neil
 * @version 0.1
 */

require_once 'includes/BitcoinECDSA.php';

// Function signatures:
// dd62ed3e: allowance(address,address)
// 095ea7b3: approve(address,uint256)
// 70a08231: balanceOf(address)
// 18160ddd: totalSupply()
// a9059cbb: transfer(address,uint256)
// 23b872dd: transferFrom(address,address,uint256)

// event Transfer(address indexed _from, address indexed _to, uint256 _value);
// event Approval(address indexed _owner, address indexed _spender, uint256 _value);

// ddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef: Transfer topic hash

//////////////////////////////////////////////////////////////////////////////////////////////config

define("QTUM_CMD_PATH","~/qtum_new/src/./qtum-cli"); //qtum-cli path
define("MAIN_QRC_ADDRESS","qdyNS7WwaNQELNRKZFoUVkNRttyM61u356"); //exchange main QRC wallet address

define("TOKEN_CONTRACT_ADDRESS","b3d16bf4ccf764fd28325df28310f2c77aef2f2b"); //QRC contract address
define("TOKEN_DECIMALS","8"); //QRC decimals
define("MIN_DEPOSIT_AMOUNT",1); //Minimum deposit amount to be detected 
define("MIN_DEPOSIT_MOVE_AMOUNT",10); //Minimum deposit amount to be moved
define("MAX_NUMBER_OF_MOVE_DEPOSITS_PER_RUN",20); //Maximum deposits to move per moveDeposits call (to avoid mempool unconfirmed parent limitation)
define("DEFAULT_FEE_AMOUNT",0.01); //default fee to send to deposit address before moving tokens out if the address does not have enough QTUM for gas
define("MIN_BALANCE_BEFORE_REFUND",0.002); //min QTUM balance a deposit address has to have, if not DEFAULT_FEE_AMOUNT will be sent to it

define("TRANSFER_EVENT_TOPIC","ddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef");

define("DEFAULT_GAS_PRICE","0.00000040");
define("DEFAULT_GAS_LIMIT","250000");

////////////////////////////////////////////////////////////////////////////////////////////////////

if(isset($argv[1])){
switch($argv[1]){
    case 'getNewDepositAddress':
        echo getNewDepositAddress().PHP_EOL;
        break;
    case 'sendTokenToAddress':
        echo sendTokenToAddress($argv[2],$argv[3]).PHP_EOL;
        break;
    case 'getTokenBalance':
        echo getTokenBalance($argv[2]).PHP_EOL;
        break;
    case 'getAddressDeposits':
        echo json_encode(getAddressDeposits($argv[2],$argv[3]),JSON_PRETTY_PRINT).PHP_EOL;
        break;
    case 'getDepositConfirmations':
        echo getDepositConfirmations($argv[2]).PHP_EOL;
        break;
    case 'moveDeposits':
        echo json_encode(moveDeposits(),JSON_PRETTY_PRINT).PHP_EOL;
        break;
    default:
        printHelp();
        break;
}
}else{
    printHelp();
}
function printHelp(){
    echo "Usage:".PHP_EOL;
    echo "getNewDepositAddress: php Qrc20.php getNewDepositAddress".PHP_EOL;
    echo "sendTokenToAddress address amount: php Qrc20.php sendTokenToAddress qVpCzEFBznr1XseP9vr7VFMfsyNNrzGBsh 112.12345678".PHP_EOL;
    echo "getTokenBalance address: php Qrc20.php getTokenBalance qVpCzEFBznr1XseP9vr7VFMfsyNNrzGBsh".PHP_EOL;
    echo "getAddressDeposits address startingblock: php Qrc20.php getAddressDeposits qVpCzEFBznr1XseP9vr7VFMfsyNNrzGBsh 500".PHP_EOL;
    echo "getDepositConfirmations txid: php Qrc20.php getDepositConfirmations a5206544436b5ba31d8261f37065f43cdbceb3641a08a43bf9b45e4ac184f6f5".PHP_EOL;
    echo "moveDeposits: php Qrc20.php moveDeposits".PHP_EOL;
}
// examples
//echo getNewDepositAddress();
//echo sendTokenToAddress("qVpCzEFBznr1XseP9vr7VFMfsyNNrzGBsh","112.12345678");
//echo getTokenBalance("qdyNS7WwaNQELNRKZFoUVkNRttyM61u356");
//print_r(getAddressDeposits("qVpCzEFBznr1XseP9vr7VFMfsyNNrzGBsh",0));
//echo getDepositConfirmations('73f7a2dd0bc8dfc4bab9309d281edd2f9daca75883752d19fca1ac11031aac84');
//echo getAddressBalance('qcr9qdFWEcvhy82j58LyoapkZpf5vdnzx9').PHP_EOL;

// getDepositConfirmations(): function that returns the number of confirmations of a deposit txid, it's important to check the number of confirmations before crediting the user account (recommended confirmations is 10+)
function getDepositConfirmations($txid){
    $result=json_decode(trim(sendCmd('gettransaction '.$txid)),true)["confirmations"];
    return $result;
}

// getAddressDeposits(): functions to get token deposits to user address after $startingBlock (including $startingBlock)
function getAddressDeposits($depositAddress,$startingBlock){
    if(!validateAddress($depositAddress))return false;
    $startingBlock=(int)$startingBlock;
    $rawresult=json_decode(trim(sendCmd('searchlogs '.$startingBlock.' 999999999 \'{"addresses": ["'.TOKEN_CONTRACT_ADDRESS.'"]}\' \'{"topics": ["'.TRANSFER_EVENT_TOPIC.'"]}\'')),true);
    $result=array();
    foreach($rawresult as $v){
        foreach($v['log'] as $vv){
            if($vv['address']==TOKEN_CONTRACT_ADDRESS && $vv['topics'][0]==TRANSFER_EVENT_TOPIC && $vv['topics'][2]==to32bytesArg(addressToHash160($depositAddress)) && getAmount($vv['data'])>MIN_DEPOSIT_AMOUNT){
                $result[]=array("depositAddress"=>$depositAddress,"blockNumber"=>$v['blockNumber'],"txid"=>$v['transactionHash'],"amount"=>getAmount($vv['data']));
            }
        }
    }
    return $result;
}

// sendTokenToAddress(): function to send tokens to a user from the main address, returns txid to check the receipt later if needed
function sendTokenToAddress($userAddress,$amount){
    if(!validateAddress($userAddress))return false;
    if(!validateAmount($amount))return false;
    $result=json_decode(trim(sendCmd('sendtocontract '.TOKEN_CONTRACT_ADDRESS.' '.'a9059cbb'.to32bytesArg(addressToHash160($userAddress)).to32bytesArg(addDecimals($amount)).' 0 '.DEFAULT_GAS_LIMIT.' '.DEFAULT_GAS_PRICE.' '.MAIN_QRC_ADDRESS)),true)["txid"];
    return $result;
}

// getTokenBalance(): function to get token balance of an address from the blockchain contract
function getTokenBalance($userAddress){
    if(!validateAddress($userAddress))return false;
    $result=json_decode(trim(sendCmd('callcontract '.TOKEN_CONTRACT_ADDRESS.' '.'70a08231'.to32bytesArg(addressToHash160($userAddress)))),true)["executionResult"]["output"];
    return getAmount($result);
}

// getNewDepositAddress(): function to get new deposit addresses for users
function getNewDepositAddress(){ 
    $newaddress=trim(sendCmd('getnewaddress ""'));
    if(validateAddress($newaddress)){
        $f = fopen("depositAddresses.txt", "a");
        fwrite($f, $newaddress.PHP_EOL); //store deposit addresses to move coins later
        fclose($f);
        return $newaddress;
    }
    return false;
}

// moveDeposits() moves the deposited coins to main address
function moveDeposits(){
    if(!file_exists("depositAddresses.txt"))return false;
    $addresses=file("depositAddresses.txt",FILE_IGNORE_NEW_LINES);
    $results=array();
    $count=0;
    foreach($addresses as $address){
        $amount=getTokenBalance($address);
        if(validateAddress($address) && $amount>=MIN_DEPOSIT_MOVE_AMOUNT){
            if(getAddressBalance($address)<MIN_BALANCE_BEFORE_REFUND)sendCmd('sendtoaddress '.$address.' '.DEFAULT_FEE_AMOUNT); // send qtum fee to the depositAddress so we can move the token out
            $results[]=array('from'=>$address,'to'=>MAIN_QRC_ADDRESS,'Tokens'=>$amount,'txid'=>json_decode(trim(sendCmd('sendtocontract '.TOKEN_CONTRACT_ADDRESS.' '.'a9059cbb'.to32bytesArg(addressToHash160(MAIN_QRC_ADDRESS)).to32bytesArg(addDecimals($amount)).' 0 '.DEFAULT_GAS_LIMIT.' '.DEFAULT_GAS_PRICE.' '.$address)),true)["txid"]);           
            $count++;
            if($count>MAX_NUMBER_OF_MOVE_DEPOSITS_PER_RUN)break;
        }
    }
    return $results;
}
//getAddressBalance() gets the balance of an address by summing up its utxos
function getAddressBalance($address){
    if(!validateAddress($address))return false;
    $balance=0;
    $result=json_decode(sendCmd('listunspent 0 999999999 [\"'.$address.'\"]'),true);
    foreach($result as $v){
        $balance+=$v['amount'];
    }
    return $balance;
}

function buildCmd($cmd)
{
    return QTUM_CMD_PATH ." ". $cmd;
}

function sendCmd($cmd){
    return trim(shell_exec(buildCmd($cmd)));
}

function validateAddress($address){
    $bitcoinECDSA = new BitcoinECDSA();
    return $bitcoinECDSA->validateAddress($address);
}

function to32bytesArg($arg){
    return str_pad($arg, 64, "0", STR_PAD_LEFT);
}

function addressToHash160($address){
    $bitcoinECDSA = new BitcoinECDSA();
    return bin2hex($bitcoinECDSA->addressToHash160($address));
}

function gmp_hexdec($n) {
    $gmp = gmp_init(0);
    $mult = gmp_init(1);
    for ($i=strlen($n)-1;$i>=0;$i--,$mult=gmp_mul($mult, 16)) {
        $gmp = gmp_add($gmp, gmp_mul($mult, hexdec($n[$i])));
    }
    return gmp_strval($gmp);
}

function gmp_dechex($dec) {
    $hex = '';
    do {
        $last = gmp_mod($dec, 16);
        $hex = dechex($last).$hex;
        $dec = gmp_div(gmp_sub($dec, $last), 16);
    } while($dec>0);
    return $hex;
}

function addDecimals($amount){
    $decimalPos=getNumberOfDecimals($amount);
    $amount= gmp_init(str_replace(".","",$amount));
    return gmp_strval(gmp_mul($amount,gmp_pow(10,(TOKEN_DECIMALS-$decimalPos))),16);
}

function getNumberOfDecimals($amount){
    if (($pos = strpos($amount, ".")) !== FALSE) {
        return strlen(substr($amount, $pos+1));
    }else{
        return 0;
    }
}

function validateAmount($amount){
    if(substr_count($amount, '.')>1) return false;
    if(getNumberOfDecimals($amount)>TOKEN_DECIMALS)return false;
    $amount=str_replace(".", "", $amount);
    if (!ctype_digit($amount))return false;
    return true;
}

function getAmount($result){
    $amount=substr_replace(gmp_hexdec($result), ".", -1*TOKEN_DECIMALS, 0);
    return substr($amount,0,1)=='.'?'0'.$amount:$amount;
}
?>
