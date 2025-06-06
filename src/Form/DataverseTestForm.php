<?php

namespace Drupal\dataverse_webform\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\dataverse_webform\DataverseClientInterface;
use Drupal\key\KeyRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for testing Dataverse connection.
 */
class DataverseTestForm extends FormBase {

  /**
   * The Dataverse client service.
   *
   * @var \Drupal\dataverse_webform\DataverseClientInterface
   */
  protected $dataverseClient;

  /**
   * The key repository service.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected $keyRepository;

  /**
   * Constructs a DataverseTestForm object.
   *
   * @param \Drupal\dataverse_webform\DataverseClientInterface $dataverse_client
   *   The Dataverse client service.
   * @param \Drupal\key\KeyRepositoryInterface $key_repository
   *   The key repository service.
   */
  public function __construct(DataverseClientInterface $dataverse_client, KeyRepositoryInterface $key_repository) {
    $this->dataverseClient = $dataverse_client;
    $this->keyRepository = $key_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('dataverse_webform.dataverse_client'),
      $container->get('key.repository')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'dataverse_webform_test_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['description'] = [
      '#markup' => '<p>' . $this->t('Use this form to test your Dataverse connection and Azure AD authentication.') . '</p>',
    ];

    // Azure AD Configuration
    $form['azure_config'] = [
      '#type' => 'details',
      '#title' => $this->t('Azure AD Configuration'),
      '#open' => TRUE,
    ];

    $form['azure_config']['azure_tenant_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Azure Tenant ID'),
      '#description' => $this->t('Your Azure AD tenant ID (GUID)'),
      '#required' => TRUE,
    ];

    // Get available keys
    $key_options = ['' => $this->t('- Select a key -')];
    $keys = $this->keyRepository->getKeys();
    foreach ($keys as $key_id => $key) {
      $key_options[$key_id] = $key->label();
    }

    $form['azure_config']['azure_client_id_key'] = [
      '#type' => 'select',
      '#title' => $this->t('Azure Client ID Key'),
      '#description' => $this->t('Select the key containing your Azure AD application client ID'),
      '#options' => $key_options,
      '#required' => TRUE,
    ];

    $form['azure_config']['azure_client_secret_key'] = [
      '#type' => 'select',
      '#title' => $this->t('Azure Client Secret Key'),
      '#description' => $this->t('Select the key containing your Azure AD application client secret'),
      '#options' => $key_options,
      '#required' => TRUE,
    ];

    // Dataverse Configuration
    $form['dataverse_config'] = [
      '#type' => 'details',
      '#title' => $this->t('Dataverse Configuration'),
      '#open' => TRUE,
    ];

    $form['dataverse_config']['dataverse_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Dataverse URL'),
      '#description' => $this->t('The base URL of your Dataverse instance (e.g., https://yourorg.crm.dynamics.com)'),
      '#required' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['test_connection'] = [
      '#type' => 'submit',
      '#value' => $this->t('Test Connection'),
      '#submit' => ['::testConnection'],
    ];

    $form['actions']['test_entities'] = [
      '#type' => 'submit',
      '#value' => $this->t('Test Entity Retrieval'),
      '#submit' => ['::testEntities'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Default submit handler - not used
  }

  /**
   * Test connection submit handler.
   */
  public function testConnection(array &$form, FormStateInterface $form_state) {
    $config = $this->buildConfig($form_state);

    if ($this->dataverseClient->testConnection($config)) {
      $this->messenger()->addStatus($this->t('✅ Connection to Dataverse successful! Azure AD authentication is working.'));
    } else {
      $this->messenger()->addError($this->t('❌ Failed to connect to Dataverse. Please check your configuration and credentials.'));
    }
  }

  /**
   * Test entities retrieval submit handler.
   */
  public function testEntities(array &$form, FormStateInterface $form_state) {
    $config = $this->buildConfig($form_state);

    $entities = $this->dataverseClient->getEntities($config);
    
    if (!empty($entities)) {
      $count = count($entities);
      $entity_names = array_slice(array_column($entities, 'display_name'), 0, 5);
      $entity_list = implode(', ', $entity_names);
      if ($count > 5) {
        $entity_list .= $this->t(' and @more more', ['@more' => $count - 5]);
      }
      
      $this->messenger()->addStatus($this->t('✅ Successfully retrieved @count entities from Dataverse. Examples: @entities', [
        '@count' => $count,
        '@entities' => $entity_list,
      ]));
      
      // Test field retrieval for the first entity
      if (!empty($entities)) {
        $first_entity = reset($entities);
        $fields = $this->dataverseClient->getEntityFields($config, $first_entity['logical_name']);
        $field_count = count($fields);
        
        $this->messenger()->addStatus($this->t('✅ Successfully retrieved @count fields for entity "@entity"', [
          '@count' => $field_count,
          '@entity' => $first_entity['display_name'],
        ]));
      }
    } else {
      $this->messenger()->addError($this->t('❌ Failed to retrieve entities from Dataverse. Connection may be working but entity access might be restricted.'));
    }
  }

  /**
   * Build configuration array from form values.
   */
  protected function buildConfig(FormStateInterface $form_state) {
    return [
      'dataverse_url' => $form_state->getValue('dataverse_url'),
      'azure_tenant_id' => $form_state->getValue('azure_tenant_id'),
      'azure_client_id_key' => $form_state->getValue('azure_client_id_key'),
      'azure_client_secret_key' => $form_state->getValue('azure_client_secret_key'),
    ];
  }

}