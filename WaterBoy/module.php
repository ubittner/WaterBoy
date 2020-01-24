<?php

/*
 * @module      WaterBoy
 *
 * @prefix      WBOY
 *
 * @file        module.php
 *
 * @author      Ulrich Bittner
 * @copyright   (c) 2020
 * @license    	CC BY-NC-SA 4.0
 *              https://creativecommons.org/licenses/by-nc-sa/4.0/
 *
 * @version     1.00-1
 * @date        2020-01-20, 18:00, 1579539600
 * @review      2020-01-24, 18:00
 *
 * @see         https://github.com/ubittner/Alarmsirene/
 *
 * @guids       Library
 *              {271B17F5-54C2-8940-8A2B-6E0762DAD94C}
 *
 *              WaterBoy
 *             	{A15A568A-E75E-6338-F0F9-6284EDE28B01}
 */

// Declare
declare(strict_types=1);

// Include
include_once __DIR__ . '/helper/autoload.php';

class WaterBoy extends IPSModule
{
    // Helper
    use WBOY_valveControl;

    public function Create()
    {
        // Never delete this line!
        parent::Create();

        // Register properties
        $this->RegisterProperties();

        // Create profiles
        $this->CreateProfiles();

        // Register variables
        $this->RegisterVariables();

        // Register timers
        $this->RegisterTimers();
    }

    public function ApplyChanges()
    {
        // Wait until IP-Symcon is started
        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        // Never delete this line!
        parent::ApplyChanges();

        // Check runlevel
        if (IPS_GetKernelRunlevel() != KR_READY) {
            return;
        }

        // Set options
        $this->SetOptions();

        // Register messages
        $this->RegisterMessages();

        // Validate configuration
        $this->ValidateConfiguration();

        // Check valve state
        $this->CheckSolenoidValveState();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        // Send debug
        $this->SendDebug('MessageSink', 'Message from SenderID ' . $SenderID . ' with Message ' . $Message . "\r\n Data: " . print_r($Data, true), 0);
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->KernelReady();
                break;

            case VM_UPDATE:
                // Solenoid valve
                if ($SenderID == $this->ReadPropertyInteger('SolenoidValve')) {
                    if ($Data[1]) {
                        $this->SendDebug(__FUNCTION__, 'Status hat sich geändert: ' . json_encode($Data[0]), 0);
                        $this->SetValue('ValveState', (int) $Data[0]);
                    }
                }
                break;

        }
    }

    protected function KernelReady()
    {
        $this->ApplyChanges();
    }

    public function Destroy()
    {
        // Never delete this line!
        parent::Destroy();

        // Delete profiles
        $this->DeleteProfiles();
    }

    public function ShowRegisteredMessages(): void
    {
        $kernelMessages = [];
        $variableMessages = [];
        foreach ($this->GetMessageList() as $id => $registeredMessage) {
            foreach ($registeredMessage as $messageType) {
                if ($messageType == IPS_KERNELSTARTED) {
                    $kernelMessages[] = ['id' => $id];
                }
                if ($messageType == VM_UPDATE) {
                    $variableMessages[] = ['id' => $id, 'name' => IPS_GetName($id)];
                }
            }
        }
        echo "IPS_KERNELSTARTED:\n\n";
        foreach ($kernelMessages as $kernelMessage) {
            echo $kernelMessage['id'] . "\n\n";
        }
        echo "\n\nVM_UPDATE:\n\n";
        foreach ($variableMessages as $variableMessage) {
            echo $variableMessage['id'] . "\n";
            echo $variableMessage['name'] . "\n";
        }
    }

    //#################### Request Action

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'SolenoidValve':
                $this->ToggleSolenoidValve($Value);
                break;

            case 'EmergencyStop':
                $this->SetValue('EmergencyStop', true);
                $close = $this->CloseSolenoidValve();
                if ($close) {
                    IPS_Sleep(500);
                    $this->SetValue('EmergencyStop', false);
                }
                break;

            case 'CycleTime':
                $this->SetCycleTime($Value);
                break;

        }
    }

    //#################### Private

    private function RegisterProperties(): void
    {
        // Visibility
        $this->RegisterPropertyBoolean('EnableSolenoidValve', true);
        $this->RegisterPropertyBoolean('EnableEmergencyStop', true);
        $this->RegisterPropertyBoolean('EnableCycleTime', true);
        $this->RegisterPropertyBoolean('EnableValveState', true);
        $this->RegisterPropertyBoolean('EnableTimerInfo', true);

        // Valve
        $this->RegisterPropertyInteger('SolenoidValve', 0);

        // Cycle time
        $this->RegisterPropertyInteger('MinValue', 1);
        $this->RegisterPropertyInteger('MaxValue', 60);
        $this->RegisterPropertyInteger('Digits', 1);
        $this->RegisterPropertyFloat('StepSize', 0.5);
    }

    private function CreateProfiles(): void
    {
        // Solenoid valve
        $profile = 'WBOY.' . $this->InstanceID . '.SolenoidValve';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 0);
        }
        IPS_SetVariableProfileAssociation($profile, 0, 'Zu', 'Execute', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, 1, 'Auf', 'Tap', 0x0000FF);

        // Emergency stop
        $profile = 'WBOY.' . $this->InstanceID . '.EmergencyStop';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 0);
        }
        IPS_SetVariableProfileAssociation($profile, 0, 'Aus', 'Warning', -1);
        IPS_SetVariableProfileAssociation($profile, 1, 'Not-Aus', 'Warning', 0xFF0000);

        // Cycle time
        $this->CreateCycleTimeProfile();

        // Valve state
        $profile = 'WBOY.' . $this->InstanceID . '.ValveState';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 1);
        }
        IPS_SetVariableProfileAssociation($profile, 0, 'Zu', 'Information', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, 1, 'Auf', 'Information', 0x0000FF);
    }

    private function DeleteProfiles(): void
    {
        $profiles = ['SolenoidValve', 'EmergencyStop', 'CycleTime', 'ValveState'];
        if (!empty($profiles)) {
            foreach ($profiles as $profile) {
                $profileName = 'WBOY.' . $this->InstanceID . '.' . $profile;
                if (IPS_VariableProfileExists($profileName)) {
                    IPS_DeleteVariableProfile($profileName);
                }
            }
        }
    }

    private function RegisterVariables(): void
    {
        // Solenoid valve
        $profile = 'WBOY.' . $this->InstanceID . '.SolenoidValve';
        $this->RegisterVariableBoolean('SolenoidValve', 'Magnetventil', $profile, 1);
        $this->EnableAction('SolenoidValve');

        // Emergency stop
        $profile = 'WBOY.' . $this->InstanceID . '.EmergencyStop';
        $this->RegisterVariableBoolean('EmergencyStop', 'Not-Aus', $profile, 2);
        $this->EnableAction('EmergencyStop');

        // Cycle time
        $profile = 'WBOY.' . $this->InstanceID . '.CycleTime';
        $this->RegisterVariableFloat('CycleTime', 'Durchlaufzeit', $profile, 3);
        $this->EnableAction('CycleTime');

        // Valve state
        $profile = 'WBOY.' . $this->InstanceID . '.ValveState';
        $this->RegisterVariableInteger('ValveState', 'Ventil-Status', $profile, 4);

        // Timer info
        $this->RegisterVariableString('TimerInfo', 'Timer', '', 5);
        $id = $this->GetIDForIdent('TimerInfo');
        IPS_SetIcon($id, 'Clock');
    }

    private function SetOptions(): void
    {
        // Solenoid valve
        $id = $this->GetIDForIdent('SolenoidValve');
        $use = $this->ReadPropertyBoolean('EnableSolenoidValve');
        IPS_SetHidden($id, !$use);

        // Emergency stop
        $id = $this->GetIDForIdent('EmergencyStop');
        $use = $this->ReadPropertyBoolean('EnableEmergencyStop');
        IPS_SetHidden($id, !$use);

        // Cycle time
        $id = $this->GetIDForIdent('CycleTime');
        $use = $this->ReadPropertyBoolean('EnableCycleTime');
        IPS_SetHidden($id, !$use);
        $this->CreateCycleTimeProfile();

        // Valve state
        $id = $this->GetIDForIdent('ValveState');
        $use = $this->ReadPropertyBoolean('EnableValveState');
        IPS_SetHidden($id, !$use);

        // Timer info
        $id = $this->GetIDForIdent('TimerInfo');
        $use = $this->ReadPropertyBoolean('EnableTimerInfo');
        IPS_SetHidden($id, !$use);
    }

    private function RegisterTimers(): void
    {
        $this->RegisterTimer('CloseSolenoidValve', 0, 'WBOY_CloseSolenoidValve(' . $this->InstanceID . ');');
    }

    private function DisableTimers(): void
    {
        $this->SetTimerInterval('CloseSolenoidValve', 0);
        $this->SetValue('TimerInfo', '-');
    }

    private function ValidateConfiguration(): void
    {
        $status = 102;
        if (!$this->CheckSolenoidValve()) {
            $status = 200;
        }
        $this->SetStatus($status);
        if ($status == 102) {
            $this->CloseSolenoidValve();
        }
    }

    private function CreateCycleTimeProfile(): void
    {
        $profile = 'WBOY.' . $this->InstanceID . '.CycleTime';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 2);
        }
        IPS_SetVariableProfileIcon($profile, 'Clock');
        IPS_SetVariableProfileValues($profile, $this->ReadPropertyInteger('MinValue'), $this->ReadPropertyInteger('MaxValue'), $this->ReadPropertyFloat('StepSize'));
        IPS_SetVariableProfileDigits($profile, $this->ReadPropertyInteger('Digits'));
        IPS_SetVariableProfileText($profile, '', ' s');
    }

    private function UnregisterMessages(): void
    {
        foreach ($this->GetMessageList() as $id => $registeredMessage) {
            foreach ($registeredMessage as $messageType) {
                if ($messageType == VM_UPDATE) {
                    $this->UnregisterMessage($id, VM_UPDATE);
                }
            }
        }
    }

    private function RegisterMessages(): void
    {
        // Unregister first
        $this->UnregisterMessages();

        // Solenoid valve
        $id = $this->ReadPropertyInteger('SolenoidValve');
        if ($id != 0 && IPS_ObjectExists($id)) {
            $this->RegisterMessage($id, VM_UPDATE);
        }
    }
}