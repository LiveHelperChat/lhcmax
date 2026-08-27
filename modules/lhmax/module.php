<?php

$Module = array( "name" => "Max Messenger",
				 'variable_params' => true );

$ViewList = array();

$ViewList['settings'] = array(
    'params' => array(),
    'uparams' => array(),
    'functions' => array('use_admin'),
);

$ViewList['test'] = array(
    'params' => array(),
    'uparams' => array(),
    'functions' => array('use_admin'),
);

$FunctionList['use_admin'] = array('explain' => 'Allow operator to configure Max Messenger integration');

?>