import '@riotjs/hot-reload'
import {component} from 'riot'
import Klarna from './klarna.riot'

component(Klarna)(document.getElementById('klarna-mount'), drupalSettings.klarnaPayments)
